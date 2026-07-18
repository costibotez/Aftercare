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

Save your admin screenshots here with these exact names (PNG, ideally at
default browser zoom on a ~1440px-wide window):

1. `screenshot-1.png` — Aftercare Dashboard (vitals cards, incidents, recent changes)
2. `screenshot-2.png` — Change Ledger timeline
3. `screenshot-3.png` — Incidents screen
4. `screenshot-4.png` — Settings screen

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
