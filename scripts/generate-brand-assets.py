#!/usr/bin/env python3
"""Regenerate the UVMASTER icons from public/images/logo.png (dépend de Pillow et numpy).

Produit : icônes Android (launcher, adaptive foreground, round), icône de notification, écrans de démarrage,
public/favicon.ico et public/favicon-32.png. Aucun visuel n'est inventé : le « UV » est extrait du logo officiel
(masque agrandi 8x puis lissé, couleurs du dégradé conservées) ; le reste (fond, cercle, marges) est géométrique.
Quand les logos officiels vectoriels seront fournis, remplacer public/images/logo.png puis relancer ce script.

Usage : python3 -I scripts/generate-brand-assets.py
"""
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

ROOT = Path(__file__).resolve().parent.parent
RES = ROOT / "android/app/src/main/res"
LOGO = ROOT / "public/images/logo.png"
SCALE = 8
WHITE = (255, 255, 255, 255)


def smoothstep(x):
    x = np.clip(x, 0.0, 1.0)
    return x * x * (3 - 2 * x)


def blur(a: np.ndarray, sigma: float) -> np.ndarray:
    """Flou gaussien séparable (numpy), pour des tableaux flottants."""
    r = int(3 * sigma)
    k = np.exp(-(np.arange(-r, r + 1) ** 2) / (2 * sigma**2))
    k /= k.sum()
    pad = np.pad(a, r, mode="edge")
    out = np.apply_along_axis(lambda v: np.convolve(v, k, mode="valid"), 1, pad)
    return np.apply_along_axis(lambda v: np.convolve(v, k, mode="valid"), 0, out)[: a.shape[0], : a.shape[1]]


def extract_glyph() -> Image.Image:
    """RGBA du « UV » (zone verte du logo), net, fond transparent, rogné au plus juste."""
    src = Image.open(LOGO).convert("RGB")
    a = np.asarray(src).astype(int)
    green = (a[:, :, 1] - np.maximum(a[:, :, 0], a[:, :, 2])) > 40
    green[60:] = False  # ignore la ligne de slogan sous le mot-symbole
    ys, xs = np.where(green)
    box = (max(xs.min() - 6, 0), max(ys.min() - 6, 0), xs.max() + 3, ys.max() + 7)
    crop = src.crop(box).resize(((box[2] - box[0]) * SCALE, (box[3] - box[1]) * SCALE), Image.LANCZOS)
    c = np.asarray(crop).astype(float)
    dist = 255.0 - c.min(axis=2)  # distance au blanc : 0 sur le fond, ~200+ dans les lettres
    sat = c.max(axis=2) - c.min(axis=2)  # le « M » noir de MASTER est neutre (sat ~ 0), le « UV » est vert saturé
    mask = smoothstep((dist - 70.0) / 60.0) * smoothstep((sat - 20.0) / 30.0)
    core = (mask > 0.98).astype(float)
    radius = 3 * SCALE
    num = np.stack([blur(c[:, :, i] * core, radius) for i in range(3)], axis=2)
    den = blur(core, radius)[:, :, None]
    fill = np.where(den > 1e-4, num / np.maximum(den, 1e-4), 0.0)  # couleur du dégradé prolongée sous les bords
    fill = np.where(core[:, :, None] > 0, c, fill)
    rgba = np.dstack([np.clip(fill, 0, 255), mask * 255.0]).astype(np.uint8)
    img = Image.fromarray(rgba, "RGBA")
    return img.crop(img.getchannel("A").point(lambda v: 255 if v > 4 else 0).getbbox())


def silhouette(glyph: Image.Image, rgb=(255, 255, 255)) -> Image.Image:
    out = Image.new("RGBA", glyph.size, rgb + (0,))
    out.putalpha(glyph.getchannel("A"))
    return out


def fit(glyph: Image.Image, width: int) -> Image.Image:
    height = max(1, round(glyph.height * width / glyph.width))
    return glyph.resize((width, height), Image.LANCZOS)


def canvas(size, glyph, width_frac, bg=None, circle=False) -> Image.Image:
    w, h = size if isinstance(size, tuple) else (size, size)
    img = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    if bg is not None:
        if circle:
            ss = 4
            big = Image.new("L", (w * ss, h * ss), 0)
            ImageDraw.Draw(big).ellipse((0, 0, w * ss - 1, h * ss - 1), fill=255)
            img.paste(Image.new("RGBA", (w, h), bg), (0, 0), big.resize((w, h), Image.LANCZOS))
        else:
            img.paste(Image.new("RGBA", (w, h), bg), (0, 0))
    g = fit(glyph, round(min(w, h) * width_frac))
    img.alpha_composite(g, ((w - g.width) // 2, (h - g.height) // 2))
    return img


def save(img: Image.Image, path: Path, flatten=False):
    path.parent.mkdir(parents=True, exist_ok=True)
    if flatten:
        base = Image.new("RGB", img.size, (255, 255, 255))
        base.paste(img, mask=img.getchannel("A"))
        base.save(path, optimize=True)
    else:
        img.save(path, optimize=True)


def prepared_logo() -> Image.Image:
    """Logo complet sur fond blanc pur (le fichier source a un fond ~254)."""
    a = np.asarray(Image.open(LOGO).convert("RGB")).astype(float)
    a = np.clip(a * 255.0 / 254.0, 0, 255)
    a[a.min(axis=2) >= 248] = 255
    return Image.fromarray(a.astype(np.uint8), "RGB")


def main():
    glyph = extract_glyph()

    # Icônes de lancement (legacy carrée / ronde, 5 densités) et premier plan adaptatif (108 dp).
    for d, legacy, fg in (("mdpi", 48, 108), ("hdpi", 72, 162), ("xhdpi", 96, 216), ("xxhdpi", 144, 324), ("xxxhdpi", 192, 432)):
        save(canvas(legacy, glyph, 0.72, WHITE), RES / f"mipmap-{d}/ic_launcher.png", flatten=True)
        save(canvas(legacy, glyph, 0.62, WHITE, circle=True), RES / f"mipmap-{d}/ic_launcher_round.png")
        save(canvas(fg, glyph, 0.54), RES / f"mipmap-{d}/ic_launcher_foreground.png")  # reste dans la zone sûre (66/108 dp)

    # Icône de notification : silhouette blanche (la barre d'état ignore les couleurs).
    sil = silhouette(glyph)
    for d, px in (("mdpi", 24), ("hdpi", 36), ("xhdpi", 48), ("xxhdpi", 72), ("xxxhdpi", 96)):
        save(canvas(px, sil, 0.92), RES / f"drawable-{d}/ic_stat_uvmaster.png")

    # Écrans de démarrage : logo complet centré sur fond blanc.
    logo = prepared_logo()
    sizes = {
        "drawable": (480, 320),
        "drawable-land-mdpi": (480, 320), "drawable-land-hdpi": (800, 480), "drawable-land-xhdpi": (1280, 720),
        "drawable-land-xxhdpi": (1600, 960), "drawable-land-xxxhdpi": (1920, 1280),
        "drawable-port-mdpi": (320, 480), "drawable-port-hdpi": (480, 800), "drawable-port-xhdpi": (720, 1280),
        "drawable-port-xxhdpi": (960, 1600), "drawable-port-xxxhdpi": (1280, 1920),
    }
    for folder, (w, h) in sizes.items():
        lw = round(min(w, h) * (0.62 if w < h else 0.5))
        lg = logo.resize((lw, round(logo.height * lw / logo.width)), Image.LANCZOS)
        lg = lg.filter(ImageFilter.UnsharpMask(radius=1.2, percent=60, threshold=2)) if lw > logo.width else lg
        img = Image.new("RGB", (w, h), (255, 255, 255))
        img.paste(lg, ((w - lg.width) // 2, (h - lg.height) // 2))
        save(img.convert("RGBA"), RES / folder / "splash.png", flatten=True)

    # Favicons : « UV » seul sur fond transparent.
    ico = [canvas(s, glyph, 0.96) for s in (16, 32, 48)]
    ico[2].save(ROOT / "public/favicon.ico", format="ICO", sizes=[(16, 16), (32, 32), (48, 48)])
    save(canvas(32, glyph, 0.96), ROOT / "public/favicon-32.png")
    print("OK glyphe", glyph.size)


if __name__ == "__main__":
    main()
