#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
from PIL import Image, ImageChops, ImageDraw, ImageFilter

ICON_SIZES = (64, 128, 256, 512)
BASE_SIZE = 512
OUTPUT_DIR = Path(__file__).resolve().parent

BG_TOP = (31, 31, 33, 255)
BG_BOTTOM = (7, 7, 8, 255)
BORDER = (255, 255, 255, 26)
SHADOW = (0, 0, 0, 150)
WHITE = (246, 246, 244, 255)
WHITE_SOFT = (255, 255, 255, 54)
CANTON_RED = (212, 28, 36, 255)
BAR_TOP = (110, 114, 118, 255)
BAR_BOTTOM = (37, 39, 43, 255)
BAR_EDGE = (255, 255, 255, 44)
GRILLE = (235, 235, 235, 88)


def vertical_gradient(size: int, top: tuple[int, int, int, int], bottom: tuple[int, int, int, int]) -> Image.Image:
    image = Image.new("RGBA", (size, size))
    px = image.load()
    for y in range(size):
        mix = y / max(size - 1, 1)
        color = tuple(int(top[i] * (1 - mix) + bottom[i] * mix) for i in range(4))
        for x in range(size):
            px[x, y] = color
    return image


def horizontal_gradient(width: int, height: int, left: tuple[int, int, int, int], right: tuple[int, int, int, int]) -> Image.Image:
    image = Image.new("RGBA", (width, height))
    px = image.load()
    for x in range(width):
        mix = x / max(width - 1, 1)
        color = tuple(int(left[i] * (1 - mix) + right[i] * mix) for i in range(4))
        for y in range(height):
            px[x, y] = color
    return image


def metallic_bar(size: int) -> Image.Image:
    width = int(size * 0.68)
    height = int(size * 0.13)
    radius = height // 2
    bar = vertical_gradient(height, BAR_TOP, BAR_BOTTOM).resize((width, height))

    mask = Image.new("L", (width, height), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, fill=255)

    border = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    border_draw = ImageDraw.Draw(border)
    border_draw.rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, outline=BAR_EDGE, width=max(1, size // 128))
    border_draw.rounded_rectangle((max(2, size // 256), max(2, size // 256), width - max(3, size // 256) - 1, height * 0.35), radius=max(2, radius // 2), outline=WHITE_SOFT, width=1)

    grille = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    grille_draw = ImageDraw.Draw(grille)
    dot_radius = max(1, size // 180)
    spacing = max(dot_radius * 4, size // 32)
    x_start = width * 0.18
    x_end = width * 0.82
    y_start = height * 0.38
    y_end = height * 0.72
    row_gap = max(dot_radius * 4, size // 40)
    y = y_start
    row = 0
    while y <= y_end:
        x_offset = spacing / 2 if row % 2 else 0
        x = x_start + x_offset
        while x <= x_end:
            grille_draw.ellipse((x - dot_radius, y - dot_radius, x + dot_radius, y + dot_radius), fill=GRILLE)
            x += spacing
        y += row_gap
        row += 1

    highlight = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    highlight_grad = horizontal_gradient(width, height, (255, 255, 255, 36), (255, 255, 255, 0))
    highlight_mask = Image.new("L", (width, height), 0)
    ImageDraw.Draw(highlight_mask).rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, fill=200)
    highlight = Image.composite(highlight_grad, highlight, highlight_mask)

    result = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    result.paste(bar, (0, 0), mask)
    result.alpha_composite(highlight)
    result.alpha_composite(grille)
    result.alpha_composite(border)
    return result


def canton_symbol(size: int) -> Image.Image:
    symbol = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(symbol)

    outer_box = (int(size * 0.15), int(size * 0.12), int(size * 0.58), int(size * 0.55))
    inner_margin = int(size * 0.09)
    inner_box = (outer_box[0] + inner_margin, outer_box[1] + inner_margin, outer_box[2] - inner_margin, outer_box[3] - inner_margin)
    draw.ellipse(outer_box, fill=WHITE)
    draw.ellipse(inner_box, fill=(0, 0, 0, 0))

    cut_x = int(size * 0.42)
    cut_box = (cut_x, outer_box[1] - int(size * 0.02), int(size * 0.62), outer_box[3] + int(size * 0.02))
    draw.rounded_rectangle(cut_box, radius=int(size * 0.045), fill=(0, 0, 0, 0))

    accent = (int(size * 0.43), int(size * 0.39), int(size * 0.53), int(size * 0.49))
    draw.rounded_rectangle(accent, radius=max(2, int(size * 0.018)), fill=CANTON_RED)

    glow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    glow_draw = ImageDraw.Draw(glow)
    glow_draw.arc(outer_box, start=216, end=32, fill=WHITE_SOFT, width=max(2, size // 64))
    glow = glow.filter(ImageFilter.GaussianBlur(radius=max(2, size // 128)))
    symbol.alpha_composite(glow)

    return symbol


def build_icon(size: int) -> Image.Image:
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))

    shadow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow)
    inset = int(size * 0.05)
    radius = int(size * 0.22)
    shadow_draw.rounded_rectangle((inset, inset + int(size * 0.018), size - inset, size - inset), radius=radius, fill=SHADOW)
    shadow = shadow.filter(ImageFilter.GaussianBlur(radius=max(2, size // 32)))
    canvas.alpha_composite(shadow)

    background = vertical_gradient(size, BG_TOP, BG_BOTTOM)
    bg_mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(bg_mask).rounded_rectangle((inset, inset, size - inset, size - inset), radius=radius, fill=255)
    canvas.paste(background, (0, 0), bg_mask)

    border_layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    border_draw = ImageDraw.Draw(border_layer)
    border_width = max(1, size // 128)
    border_draw.rounded_rectangle((inset, inset, size - inset, size - inset), radius=radius, outline=BORDER, width=border_width)
    canvas.alpha_composite(border_layer)

    vignette = Image.new("L", (size, size), 0)
    vignette_draw = ImageDraw.Draw(vignette)
    vignette_draw.ellipse((int(size * 0.08), int(size * 0.02), int(size * 0.92), int(size * 0.92)), fill=180)
    vignette = ImageChops.invert(vignette.filter(ImageFilter.GaussianBlur(radius=max(6, size // 8))))
    vignette_layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    vignette_layer.putalpha(vignette)
    canvas.alpha_composite(vignette_layer)

    symbol = canton_symbol(size)
    canvas.alpha_composite(symbol)

    bar = metallic_bar(size)
    bar_x = (size - bar.width) // 2
    bar_y = int(size * 0.63)

    bar_shadow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    bar_shadow_draw = ImageDraw.Draw(bar_shadow)
    bar_shadow_draw.rounded_rectangle((bar_x, bar_y + max(2, size // 96), bar_x + bar.width, bar_y + bar.height + max(2, size // 96)), radius=bar.height // 2, fill=(0, 0, 0, 92))
    bar_shadow = bar_shadow.filter(ImageFilter.GaussianBlur(radius=max(2, size // 48)))
    canvas.alpha_composite(bar_shadow)
    canvas.alpha_composite(bar, (bar_x, bar_y))

    specular = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    spec_draw = ImageDraw.Draw(specular)
    spec_draw.ellipse((int(size * 0.16), int(size * 0.10), int(size * 0.58), int(size * 0.40)), fill=(255, 255, 255, 18))
    specular = specular.filter(ImageFilter.GaussianBlur(radius=max(2, size // 24)))
    canvas.alpha_composite(specular)

    return canvas


def main() -> None:
    for size in ICON_SIZES:
        output = OUTPUT_DIR / f"icon_{size}.png"
        build_icon(size).save(output)
        print(f"Wrote {output.name}")


if __name__ == "__main__":
    main()


