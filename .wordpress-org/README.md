# WordPress.org directory assets

These files go into the **SVN `assets/` folder** of the plugin (a sibling of
`trunk/` and `tags/`), *not* into the plugin zip. They control how the plugin
looks in the directory listing.

| File | Purpose |
|---|---|
| `banner-772x250.png` | Directory page header banner |
| `banner-1544x500.png` | Retina banner (2×) |
| `icon-128x128.png` | Listing icon |
| `icon-256x256.png` | Retina icon (2×) |
| `screenshot-1.png` … `screenshot-4.png` | Screenshots, numbered to match the captions in `readme.txt` |

## Screenshots

The committed `screenshot-1..4.png` are rendered from the mockups in `src/`
(they use the plugin's real admin CSS and sparkline renderer with
representative data):

1. `screenshot-1.png` — Aftercare Dashboard (vitals cards, incidents, recent changes)
2. `screenshot-2.png` — Change Ledger timeline
3. `screenshot-3.png` — Incident detail with attribution and the 72-hour window
4. `screenshot-4.png` — Settings screen

To use real captures instead, overwrite these files with exports from a live
install (PNG, default browser zoom, ~1280px-wide window) keeping the same
names.

## Regenerating the banner/icon

The sources live in `src/`. Re-render with the Playwright headless shell (or
any Chromium):

```
headless_shell --no-sandbox --hide-scrollbars --force-device-scale-factor=1 \
  --window-size=772,250 --screenshot=banner-772x250.png file://$PWD/src/banner.html
headless_shell --no-sandbox --hide-scrollbars --force-device-scale-factor=2 \
  --window-size=772,250 --screenshot=banner-1544x500.png file://$PWD/src/banner.html
headless_shell --no-sandbox --hide-scrollbars --force-device-scale-factor=1 \
  --window-size=256,256 --screenshot=icon-256x256.png file://$PWD/src/icon.html
headless_shell --no-sandbox --hide-scrollbars --force-device-scale-factor=1 \
  --window-size=128,128 --screenshot=icon-128x128.png file://$PWD/src/icon.html
```
