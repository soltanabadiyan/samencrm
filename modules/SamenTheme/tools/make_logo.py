#!/usr/bin/env python3
"""Compose the CRM company logo: Samen emblem + "SamenCRM" + "سامانه مدیریت ارتباط با مشتری" (Shabnam, needs PIL with raqm).
   python3 tools/make_logo.py  ->  resources/img/company-logo.png (used as Vtiger company logo)"""
import os
from PIL import Image, ImageDraw, ImageFont, features
here = os.path.dirname(os.path.abspath(__file__))
res = os.path.join(here, "..", "layouts/v7/modules/SamenTheme/resources")
H = 120
emblem = Image.open(os.path.join(res, "img/emblem.png")).convert("RGBA")
emblem = emblem.resize((round(emblem.width * H / emblem.height), H), Image.LANCZOS)
font = ImageFont.truetype(os.path.join(res, "fonts/Shabnam.ttf"), 64, layout_engine=ImageFont.Layout.RAQM)
small = ImageFont.truetype(os.path.join(res, "fonts/Shabnam.ttf"), 26, layout_engine=ImageFont.Layout.RAQM)
title, sub = "SamenCRM", "سامانه مدیریت ارتباط با مشتری"
d0 = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
tw = d0.textlength(title, font=font)
sw = d0.textlength(sub, font=small, direction="rtl", language="fa")
tw_max = int(max(tw, sw)) + 8
img = Image.new("RGBA", (emblem.width + 24 + tw_max, H), (0, 0, 0, 0))
img.paste(emblem, (img.width - emblem.width, 0), emblem)          # emblem on the right (RTL reading start)
d = ImageDraw.Draw(img)
x_right = img.width - emblem.width - 18
# Latin wordmark reads left-to-right: "Samen" (teal) then "CRM" (brown), right-aligned against the emblem
d.text((x_right, 2), "CRM", font=font, fill=(125, 79, 46, 255), anchor="ra")
d.text((x_right - d.textlength("CRM", font=font) - 2, 2), "Samen", font=font, fill=(19, 96, 111, 255), anchor="ra")
d.text((x_right, 84), sub, font=small, fill=(125, 79, 46, 255), anchor="ra", direction="rtl", language="fa")
img = img.crop(img.getbbox())
img.save(os.path.join(res, "img/company-logo.png"), optimize=True)
print("company-logo.png", img.size, "raqm", features.check("raqm"))
