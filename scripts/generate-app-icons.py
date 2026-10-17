#!/usr/bin/env python3
"""Génère toutes les icônes de l'application (Android et web) à partir de resources/branding/app-icon.png.

    python3 scripts/generate-app-icons.py

La source est un badge arrondi (cadre vert, intérieur blanc, « UV MASTER ») sur fond blanc opaque avec une ombre. Le script en
tire deux éléments :
  - le BADGE (forme arrondie détectée sur le cadre vert, ombre exclue) : icône classique, Play Store, favicon, icône iOS ;
  - la MARQUE (« UV MASTER » seul, fond blanc retiré par « color to alpha ») : premier plan de l'icône adaptative d'Android,
    placé dans la zone sûre (66 % centraux de la grille 108 dp) car le lanceur découpe l'icône en rond ou en carré arrondi.

Ne touche PAS au glyphe « UV » du splash (mipmap ic_launcher_foreground) : le splash et l'écran de chargement natif en dépendent.
Dépendances : Pillow et numpy (déjà utilisés par generate-dark-logo.py).
"""

from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'resources/branding/app-icon.png'
RES = ROOT / 'android/app/src/main/res'
PUBLIC = ROOT / 'public'

# densité Android -> (côté du pictogramme classique en px, côté de la grille adaptative 108 dp en px)
DENSITIES = {'mdpi': (48, 108), 'hdpi': (72, 162), 'xhdpi': (96, 216), 'xxhdpi': (144, 324), 'xxxhdpi': (192, 432)}

# Largeur de « UV MASTER » dans la grille adaptative. Le lanceur ne garantit que le disque central de 66 dp (sur 108) : le
# rectangle du texte (proportion 810 x 568) doit y tenir par ses COINS, soit au plus 54 dp, donc 0,50 de la grille.
SAFE_WIDTH = 0.50


def badge_shape(src: Image.Image) -> Image.Image:
    """Masque (L) du badge : cadre vert + intérieur, sans l'ombre. On remplit depuis un coin tout ce qui n'est pas le cadre."""
    rgb = np.array(src.convert('RGB')).astype(int)
    ring = (rgb.max(axis=2) - rgb.min(axis=2)) > 40  # le cadre est saturé ; l'ombre et le fond sont neutres
    ring_img = Image.fromarray((ring * 255).astype('uint8'), 'L')
    outside = ring_img.copy()
    ImageDraw.floodfill(outside, (0, 0), 128, thresh=0)  # tout ce qui touche le coin sans traverser le cadre
    inside = (np.array(outside) != 128)
    mask = Image.fromarray((inside * 255).astype('uint8'), 'L')

    return mask.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(1.2))


def squared(box, limit):
    left, top, right, bottom = box
    side = max(right - left, bottom - top)
    cx, cy = (left + right) // 2, (top + bottom) // 2
    half = side // 2

    return (max(0, cx - half), max(0, cy - half), min(limit[0], cx + half), min(limit[1], cy + half))


def badge_rgba(src: Image.Image, mask: Image.Image) -> Image.Image:
    box = squared(mask.getbbox(), src.size)
    badge = src.convert('RGBA').crop(box)
    badge.putalpha(mask.crop(box))

    return badge


def mark_rgba(src: Image.Image, mask: Image.Image) -> Image.Image:
    """« UV MASTER » seul : on isole l'encre à l'intérieur du cadre, puis on retire le blanc (color to alpha)."""
    rgb = np.array(src.convert('RGB')).astype(int)
    left, top, right, bottom = mask.getbbox()
    margin = int((right - left) * 0.13)  # on reste à l'intérieur du cadre
    ink = rgb.min(axis=2) < 215
    window = np.zeros_like(ink)
    window[top + margin:bottom - margin, left + margin:right - margin] = True
    ys, xs = np.where(ink & window)
    box = (xs.min() - 6, ys.min() - 6, xs.max() + 7, ys.max() + 7)
    crop = np.array(src.convert('RGB').crop(box)).astype(float)

    alpha = np.clip((255 - crop).max(axis=2) / 255.0, 0, 1)
    alpha[alpha < 0.04] = 0  # le fond de la source n'est pas un blanc parfait : on retire ce voile quasi invisible
    safe = np.where(alpha > 0.004, alpha, 1)[..., None]
    color = np.clip((crop - 255 * (1 - alpha[..., None])) / safe, 0, 255)
    out = np.dstack([color, alpha * 255]).astype('uint8')

    return Image.fromarray(out, 'RGBA')


def on_canvas(mark: Image.Image, side: int, width_ratio: float, background=(0, 0, 0, 0)) -> Image.Image:
    canvas = Image.new('RGBA', (side, side), background)
    width = int(side * width_ratio)
    height = int(width * mark.height / mark.width)
    if height > side * width_ratio:  # jamais plus haut que la zone sûre
        height = int(side * width_ratio)
        width = int(height * mark.width / mark.height)
    resized = mark.resize((width, height), Image.LANCZOS)
    canvas.alpha_composite(resized, ((side - width) // 2, (side - height) // 2))

    return canvas


def circle(side: int) -> Image.Image:
    big = Image.new('L', (side * 4, side * 4), 0)
    ImageDraw.Draw(big).ellipse((0, 0, side * 4 - 1, side * 4 - 1), fill=255)

    return big.resize((side, side), Image.LANCZOS)


def save(image: Image.Image, path: Path, **kwargs) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, optimize=True, **kwargs)
    print('  écrit', path.relative_to(ROOT), image.size)


def white_square(badge: Image.Image, side: int, fill: float) -> Image.Image:
    """Badge centré sur un carré blanc plein (Play Store, icône iOS : le système applique son propre masque)."""
    canvas = Image.new('RGBA', (side, side), (255, 255, 255, 255))
    size = int(side * fill)
    canvas.alpha_composite(badge.resize((size, size), Image.LANCZOS), ((side - size) // 2, (side - size) // 2))

    return canvas.convert('RGB')


def main() -> None:
    src = Image.open(SOURCE)
    mask = badge_shape(src)
    badge = badge_rgba(src, mask)
    mark = mark_rgba(src, mask)
    print('Badge', badge.size, '| marque', mark.size)

    print('Android — icônes classiques et adaptatives')
    for name, (legacy, adaptive) in DENSITIES.items():
        folder = RES / f'mipmap-{name}'
        # Anciens Android (avant 8.0) : le badge tel quel, coins arrondis transparents.
        save(badge.resize((legacy, legacy), Image.LANCZOS), folder / 'ic_launcher.png')
        # Icône ronde classique : « UV MASTER » sur un disque blanc.
        disc = on_canvas(mark, legacy, 0.66, (255, 255, 255, 255))
        disc.putalpha(circle(legacy))
        save(disc, folder / 'ic_launcher_round.png')
        # Icône adaptative (Android 8+) : premier plan transparent dans la zone sûre, fond blanc défini en couleur.
        save(on_canvas(mark, adaptive, SAFE_WIDTH), folder / 'ic_launcher_brand_foreground.png')

    save(white_square(badge, 512, 0.94), ROOT / 'android/app/src/main/ic_launcher-playstore.png')

    print('Web — favicon, icône iOS')
    save(badge.resize((32, 32), Image.LANCZOS), PUBLIC / 'favicon-32.png')
    save(white_square(badge, 180, 0.92), PUBLIC / 'apple-touch-icon.png')
    badge.resize((256, 256), Image.LANCZOS).save(PUBLIC / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    print('  écrit public/favicon.ico (16, 32, 48)')


if __name__ == '__main__':
    main()
