# YouTubeDuration
FreshRSS extension that adds YouTube video duration or Shorts markers to new YouTube entry titles using the YouTube Data API. Uses a local cache and configurable system-wide formatting. It uses a free YouTube Data v3 API Key you can get from your Google Cloud Dashboard.

## This extension is built completely via AI.
I don't know much about FreshRSS extensions and I'm just a novice with PHP. So, I won't claim that I thoroughly understand how it works. But I tested it on my personal single-user FreshRSS setup for weeks without issues.
I understand most people have reservations about using slopcoded applications. Still, feel free to analyze, use, debug, revise, or otherwise comment on the code. The code is yours, hoping it will make your RSS setup a bit more useful.

## Installation
Assuming you already have a running FreshRSS instance, installing the extension is just dropping its folder into FreshRSS's `extensions/` directory and enabling it from the web UI.

### 1. Put the files in place

The goal is to have the extension's files at `<FreshRSS>/extensions/xExtension-YouTubeDuration/`:

```bash
cd /.../FreshRSS/extensions
# or depending on your setup, /.../FreshRSS/public/extensions
git clone https://github.com/cevvalkoala/xExtension-YouTubeDuration.git
# make sure the web server user can read it (skip if not applicable)
chown -R www-data:www-data xExtension-YouTubeDuration
```

### 2. Enable it in FreshRSS

1. Open FreshRSS → **Settings (gear) → Extensions**.
2. Find **YouTube Duration** in the list and click **Enable**.
3. Click on the gears icon next to the extension's name, to open its configuration page.
4. Enter the Youtube Data API Key you obtained from Google Cloud Dashboard.
   4.1. Create a new project at https://console.cloud.google.com
   4.2. Proceed to APIs & Services, Enable APIs and services. YouTube Data API v3 should be enabled. For personal use, the quota Google gives you (10K queries a day) should be more than sufficient. Also, the extension caches video queries, so that a given video is queried only once.
   4.3. Create the API key on the "Credentials" page under "APIs & Services".
5. Check out the other few options provided.
6. Click "Save".
7. Return to your feeds. The next time a Youtube feed item appears in one of your Youtube feeds, its duration will be shown in the title. Mobile RSS apps such as Capy Reader or Feedme will also show the title with duration.
8. Existing Youtube feed items will not be affected. Feel free to come up with a backfill utility to check the existing items in your existing Youtube feeds. I didn't want to slopcode a small script to go over my existing feeds database.

To update at a later time, I guess `git pull` inside the folder (or re‑copy the files) and hard‑refresh would do the trick.
