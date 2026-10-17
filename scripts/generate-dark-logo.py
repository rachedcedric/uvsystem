#!/usr/bin/env python3
"""Génère la variante « mode sombre » du logo large UVMASTER (dépend de Pillow et numpy).

Le logo officiel (public/images/logo-wide.png) est un PNG RVB à fond blanc opaque, avec un texte noir : sur un fond
sombre il ne peut donc reposer que sur une pastille blanche. Ce script produit un NOUVEAU fichier, public/images/logo-wide-dark.png :
  - le fond blanc devient transparent (extraction « couleur vers alpha », anti-crénelage conservé) ;
  - l'encre neutre (texte noir et gris) devient la couleur de texte claire de l'interface sombre ;
  - le « UV » vert garde ses couleurs d'origine.
Aucune image existante n'est modifiée ni écrasée. Les dimensions sont celles du logo d'origine.

Usage : python3 -I scripts/generate-dark-logo.py
"""
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "public/images/logo-wide.png"
TARGET = ROOT / "public/images/logo-wide-dark.png"

# Couleur de texte de l'interface sombre (--fg dans resources/css/mobile.css).
LIGHT_INK = np.array([230.0, 240.0, 232.0])
# Écart max-min en dessous duquel une encre est considérée comme neutre (noir / gris).
NEUTRAL_CHROMA = 60.0


def main() -> None:
    rgb = np.asarray(Image.open(SOURCE).convert("RGB"), dtype=np.float64)

    # Opacité de l'encre sur fond blanc : 0 pour le blanc pur, 1 pour une encre qui annule un canal.
    alpha = 1.0 - rgb.min(axis=2) / 255.0
    safe = np.maximum(alpha, 1e-3)[..., None]

    # Couleur de l'encre seule, débarrassée du fond blanc.
    ink = np.clip((rgb - 255.0 * (1.0 - alpha[..., None])) / safe, 0.0, 255.0)

    # Encre neutre -> couleur de texte claire ; encre colorée (le « UV » vert) inchangée. Transition douce.
    chroma = ink.max(axis=2) - ink.min(axis=2)
    neutral = np.clip(1.0 - chroma / NEUTRAL_CHROMA, 0.0, 1.0)[..., None]
    color = neutral * LIGHT_INK + (1.0 - neutral) * ink

    out = np.dstack([np.rint(color), np.rint(alpha * 255.0)]).astype(np.uint8)
    Image.fromarray(out, "RGBA").save(TARGET, optimize=True)
    print(f"{TARGET.relative_to(ROOT)} : {out.shape[1]}x{out.shape[0]} RGBA")


if __name__ == "__main__":
    main()
