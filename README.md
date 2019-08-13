# WP Profile Moderation

Do you run a forum with wpForo and have issues with inappropriate profile images being uploaded by users? This plugin can help you.
When users upload profile images, we process them via Google Vision API Safe-search, which can recognise things like nudity. The results from the safe-search are shown a score against the user listing in WP Users admin. Periodically you can check if any scores are high and view the images yourself. This plugin does not block or otherwise interfere with the users uploads.

v 0.1 is a proof of concept so is not featue rich, but I am open to suggestions! Mistral

__Requires :__

- PHP 5.4+
- https://en-gb.wordpress.org/plugins/wpforo/

- Google Vision API key


#####  WPForo Companion Plugin

The current version of this plugin only works with the wpForo plugin, when profiles are updated by a user. If you don't have wpForo installed and allow profile images to be uploaded it won't do you any good.


##### Installation

Install the plugin in your plugin folder via FTP or direct upload and activate. Visit the plugin settings page and enter a valid API Key

##### Google Vision API key

The plugin requires a valid Google Vision API key to work. This is not the key file that you are given initially, it's a single alpahnumeric string eg 'AIaxSyBdJPOEXMaagGFhZw67faOAXVJz14_3bHk'.

https://cloud.google.com/vision/docs/before-you-begin

https://cloud.google.com/docs/authentication/api-keys

## Understanding the scores
On the standard users listing page, a new column is added to show a score.

To allow for variations with the results from different APIs, we normalise the results into a single score 1-4.
The score shown, is the highest score across all the categories for the Google Vision safe search. Those categories are (adult, spoof, medical, violence, and racy).
- VERY_UNLIKELY = 0
- UNLIKELY = 1
- POSSIBLE = 2
- LIKELY = 3
- VERY_LIKELY = 4

NB: mouseover the score to see the full data for each category.

## Roadmap

- add other image APIs - AWS, Microsoft
- add bulk processing for existing profile images
- add ignore function in Users listing, to exclude specific results
- add ignore role to preferences
- add alerts to admin when inappropriate images are found

## Support
Please submit issues or suggestions via the project. https://github.com/Mistralis/wp-profile-moderation

## License

[GPLv2+](http://www.gnu.org/licenses/gpl-2.0.html)


