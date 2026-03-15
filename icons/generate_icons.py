#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ICON_SIZES = (64, 128, 256, 512)
OUTPUT_DIR = Path(__file__).resolve().parent

DARK_BLUE = (14, 49, 92, 255)
CANTON_RED = (205, 33, 42, 255)
BAR_TOP = (123, 129, 139, 255)
BAR_BOTTOM = (42, 46, 54, 255)
BAR_EDGE = (255, 255, 255, 62)
BAR_GRILLE = (232, 238, 245, 120)
SHADOW = (0, 0, 0, 28)


def vertical_gradient(width: int, height: int, top: tuple[int, int, int, int], bottom: tuple[int, int, int, int]) -> Image.Image:
    image = Image.new("RGBA", (width, height))
    px = image.load()
    for y in range(height):
        mix = y / max(height - 1, 1)
        color = tuple(int(top[i] * (1 - mix) + bottom[i] * mix) for i in range(4))
        for x in range(width):
            px[x, y] = color
    return image


def load_wordmark_font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-BoldOblique.ttf",
        "/usr/share/fonts/truetype/liberation2/LiberationSans-BoldItalic.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    ]
    for path in candidates:
        try:
            return ImageFont.truetype(path, size=size)
        except OSError:
            continue
    return ImageFont.load_default()


def metallic_bar(size: int) -> Image.Image:
    width = int(size * 0.80)
    height = max(6, int(size * 0.10))
    radius = height // 2
    bar = vertical_gradient(width, height, BAR_TOP, BAR_BOTTOM)

    mask = Image.new("L", (width, height), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, fill=255)

    border = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    border_draw = ImageDraw.Draw(border)
    border_draw.rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, outline=BAR_EDGE, width=max(1, size // 128))

    grille = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    grille_draw = ImageDraw.Draw(grille)
    dot_radius = max(1, size // 220)
    spacing = max(dot_radius * 4, size // 34)
    x_start = width * 0.16
    x_end = width * 0.84
    y_start = height * 0.34
    y_end = height * 0.72
    row_gap = max(dot_radius * 4, size // 48)
    y = y_start
    row = 0
    while y <= y_end:
        x_offset = spacing / 2 if row % 2 else 0
        x = x_start + x_offset
        while x <= x_end:
            grille_draw.ellipse((x - dot_radius, y - dot_radius, x + dot_radius, y + dot_radius), fill=BAR_GRILLE)
            x += spacing
        y += row_gap
        row += 1

    highlight = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    highlight_grad = Image.new("RGBA", (width, height), (255, 255, 255, 0))
    hg_draw = ImageDraw.Draw(highlight_grad)
    hg_draw.rounded_rectangle((0, 0, width - 1, int(height * 0.42)), radius=max(2, radius // 2), fill=(255, 255, 255, 38))
    highlight_mask = Image.new("L", (width, height), 0)
    ImageDraw.Draw(highlight_mask).rounded_rectangle((0, 0, width - 1, height - 1), radius=radius, fill=200)
    highlight = Image.composite(highlight_grad, highlight, highlight_mask)

    result = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    result.paste(bar, (0, 0), mask)
    result.alpha_composite(highlight)
    result.alpha_composite(grille)
    result.alpha_composite(border)
    return result


def canton_symbol(size: int, color: tuple[int, int, int, int]) -> Image.Image:
    symbol = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(symbol)

    outer_box = (int(size * 0.14), int(size * 0.16), int(size * 0.62), int(size * 0.64))
    inner_margin = int(size * 0.10)
    inner_box = (outer_box[0] + inner_margin, outer_box[1] + inner_margin, outer_box[2] - inner_margin, outer_box[3] - inner_margin)
    draw.ellipse(outer_box, fill=color)
    draw.ellipse(inner_box, fill=(0, 0, 0, 0))

    cut_x = int(size * 0.42)
    cut_box = (cut_x, outer_box[1] - int(size * 0.03), int(size * 0.71), outer_box[3] + int(size * 0.03))
    draw.rounded_rectangle(cut_box, radius=max(2, int(size * 0.06)), fill=(0, 0, 0, 0))

    accent = (int(size * 0.48), int(size * 0.47), int(size * 0.58), int(size * 0.57))
    draw.rounded_rectangle(accent, radius=max(1, int(size * 0.014)), fill=CANTON_RED)

    return symbol


def draw_tracking_text(draw: ImageDraw.ImageDraw, text: str, x: int, y: int, font: ImageFont.FreeTypeFont | ImageFont.ImageFont, fill: tuple[int, int, int, int], tracking: int) -> tuple[int, int]:
    cursor_x = x
    max_bottom = y
    for index, ch in enumerate(text):
        bbox = draw.textbbox((cursor_x, y), ch, font=font)
        draw.text((cursor_x, y), ch, fill=fill, font=font)
        char_w = bbox[2] - bbox[0]
        max_bottom = max(max_bottom, bbox[3])
        cursor_x += char_w + (tracking if index < len(text) - 1 else 0)
    return cursor_x - x, max_bottom - y


def draw_wordmark(size: int) -> Image.Image:
    layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)

    text = "canton"
    font = load_wordmark_font(max(11, int(size * 0.155)))
    tracking = max(0, size // 120)
    dummy_x = 0
    text_w = 0
    text_h = 0
    for ch in text:
        bbox = draw.textbbox((dummy_x, 0), ch, font=font)
        text_w += (bbox[2] - bbox[0])
        text_h = max(text_h, bbox[3] - bbox[1])
    text_w += tracking * (len(text) - 1)

    symbol_size = int(size * 0.24)
    mark_gap = int(size * 0.022)
    total_w = symbol_size + mark_gap + text_w
    x0 = (size - total_w) // 2
    y0 = int(size * 0.17)

    mark = canton_symbol(symbol_size, DARK_BLUE)
    layer.alpha_composite(mark, (x0, y0 + max(0, (text_h - symbol_size) // 2) + int(size * 0.004)))
    draw_tracking_text(draw, text, x0 + symbol_size + mark_gap, y0, font, DARK_BLUE, tracking)
    return layer


def build_icon(size: int) -> Image.Image:
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))

    wordmark = draw_wordmark(size)
    canvas.alpha_composite(wordmark)

    bar = metallic_bar(size)
    bar_x = (size - bar.width) // 2
    bar_y = int(size * 0.70)

    bar_shadow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    bar_shadow_draw = ImageDraw.Draw(bar_shadow)
    bar_shadow_draw.rounded_rectangle(
        (bar_x, bar_y + max(1, size // 128), bar_x + bar.width, bar_y + bar.height + max(1, size // 128)),
        radius=bar.height // 2,
        fill=SHADOW,
    )
    bar_shadow = bar_shadow.filter(ImageFilter.GaussianBlur(radius=max(1, size // 72)))
    canvas.alpha_composite(bar_shadow)
    canvas.alpha_composite(bar, (bar_x, bar_y))

    return canvas


def main() -> None:
    for size in ICON_SIZES:
        output = OUTPUT_DIR / f"icon_{size}.png"
        build_icon(size).save(output)
        print(f"Wrote {output.name}")


if __name__ == "__main__":
    main()

