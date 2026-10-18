# WiFiLogger direct upload

If your Davis Vantage Pro2 or Vue already runs a WiFiLogger for its internet connection, you do not need to add an AviationWX Bridge. A WiFiLogger can push its current conditions straight to your AviationWX dashboard from its "Custom Export Setup" screen.

This guide covers the device side. The airport admin gives you one ready-to-paste URL; the rest is entering it and checking that data shows up.

## Before you start

You need three things:

- A WiFiLogger with internet access and its web UI reachable from a browser on the same network.
- The upload URL from your AviationWX contact. It looks like `https://api.aviationwx.org/v1/upload/weather/wifilogger/awxu_...`. Do not retype it by hand; copy and paste it.
- The station's MAC address. If the admin asks for it, it is on the WiFiLogger's "System" or "Network" page, in the form `aa:bb:cc:dd:ee:ff`.

## Configure the export

1. Open the WiFiLogger web UI in a browser and log in.
2. Go to **Custom Export Setup**, then the **HTTP** tab.
3. Choose the **PHP → MySQL** export mode if it is not already selected.
4. In the **Your Server** field, paste the full URL you were given, token included.
5. Set **Interval** to how often you want the station to report. 5 seconds is fine for a local weather page. Longer intervals also work and use less data.
6. Save.

There is no username or password to enter on the HTTP tab. The export has only those two fields. The long token in the URL is what connects your station to the right airport.

## Confirm it works

- After a refresh cycle, open your airport's AviationWX page and check that temperature, wind, and pressure update.
- The station also sends a one-time "registration" message with its model and firmware. You do not need to act on it; AviationWX uses it to confirm the station identity.

## What the station sends

A WiFiLogger reports Davis-native values: outside temperature, humidity, dew point, barometer or altimeter, wind speed and direction, gusts, and rain. AviationWX prefers the altimeter for pressure, treats wind as true north, and discards any reading outside a sane range.

For wind to be accurate, the Davis vane must be installed and aimed to true north. AviationWX does not apply a magnetic correction.

## If it does not update

- **No data at all:** confirm the URL is pasted exactly, that Interval is set, and that the WiFiLogger has an internet connection. The WiFiLogger's own status page usually shows the last export attempt and any HTTP error.
- **Rejected (4xx):** the station MAC or the Davis station id does not match what the admin configured. Send the admin the MAC from the WiFiLogger's System page and the `wid` value if you know it.
- **Stale after a while:** the interval may be too long, or the station lost power or internet. Check the WiFiLogger status page.

Questions go to `contact@aviationwx.org`.