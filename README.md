# PitchPrint for Wordpress

This PichPrint plugin for Wordpress provides an interface between PitchPrint and Wordpress.
It retrieves all your designs from your PitchPrint account for you to select and assign to a product. When opening the product on the frontend, it will have the selected design ready for customization.
You can link your PitchPrint account to Wordpress, by providing your domain API key and Secret Key in the PitchPrint Plugin for Wordpress's settings page.
The plugin also emits events when certain actions take place. These events send information to an endpoint that you can specify on the Webhooks page of PitchPrint https://admin.pitchprint.io/webhooks

The plugin allows you to do the following on Wordpress:

* Assign a PitchPrint design to a product
* Choose the display mode of PitchPrint on a per product basis. ( Fullscreen, Inline, Mini )
* Indicate whether it is compulsory for a product to be customized, before add to cart is possible.
* Send information about a project / order when certain actions take place, these are the list of available events:
  * When a file is uploaded
  * When a project get's saved
  * When an order is being processed
  * When an order is completed

How to install PitchPrint on Wordpress: https://docs.pitchprint.com/article/35-how-to-install-pitchprint-on-wordpress

Demo of PitchPrint on Wordpress: https://wp.demo.pitchprint.io/
