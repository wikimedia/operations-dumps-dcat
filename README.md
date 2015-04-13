DCAT-AP for Wikibase
=================

A project aimed at generating a [DCAT-AP](https://joinup.ec.europa.eu/system/files/project/c3/22/18/DCAT-AP_Final_v1.00.html)
document for [Wikibase](http://wikiba.se) installations
in general and [Wikidata](http://wikidata.org) in particular.

Takes into account access through:

*   Content negotiation (various formats)
*   MediaWiki api (various formats)
*   JSON dumps (assumes that these are gziped)

Current result can be found at [lokal-profil / dcat-wikidata.rdf](https://gist.github.com/lokal-profil/8086dc6bf2398d84a311)


## To use

1.  Copy `config.example.json` to `config.json` and change the contents
    to match your installation. Refer to `config.explanations.json` for
    the individual configuration parameters.
2.  Copy `catalog.example.json` to a suitable place (e.g. on-wiki) and
    update the translations to fit your wikibase installation. Set this
    value as `catalog-i18n` in the config file.
3.  Create the dcatap.rdf file by running `php -r "require 'DCAT.php'; run('<PATH>');"`
    where `<PATH>` is the relative path to the directory containing the
    dumps (if any) and where the dcatap.rdf file should be created.
    `<PATH>` can be left out if already supplied through the `directory`
    parameter in the config file.


## Translations

*   Translations which are generic to the tool can be submitted as pull
    requests and should be in the same format as the files in the `i18n`
    directory.
*   Translations which are specific to a project/catalog are added to
    the location specified in the `catalog-i18n` parameter of the config
    file.
