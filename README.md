# Bonnier Willow BTS

A wordpress / willow plugin for handling automatic
translations using the external LanguageWire service.

The plugin uses Polylang to have different versions of the
same post / page, and uses a service called
Bonnier Translation Service (BTS for short) to mediate
between Wordpress and LanguageWire.

## Requirements

* Wordpress 4.9 or higher
* PHP 7.1 or higher
* Polylang
* Amazon AWS package

## Installation / Configuration

Install through composer:

```
composer require benjaminmedia/wp-willow-bts
```

**OR** by using the respository:
https://github.com/BenjaminMedia/willow-bts-plugin
and copying the contents to the wp-content/plugins folder.

Remember to run
```
composer install
```
Before activating the plugin in Wordpress plugin manager.

### Plugin settings

The settings for the plugin, can be found in the side menu
under:
**Settings -> Bonnier Willow BTS**

#### Site short handle

This is the key part of the BTS setup.

This handle should be unique pr site, so each site can
get the translated articles, they have sent to LanguageWire.

#### AWS settings

These settings are needed to send data to BTS, as we are
using the Simple Notification Service (SNS) from Amazon to
exchange data between the systems.

All settings are currently being managed by MartinShi.

#### Language Wire Settings

These are the settings that are needed internally in 
Language Wire.

All the settings here are managed by Language Wire, and
are most likely different pr site.