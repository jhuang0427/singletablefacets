# Single Table Facets

This class is intended as a simple faceted search solution for PHP applications where the data source is a single MySQL table. It does not require any joins or relationships. If you want faceted search, but you want the data source to be as simple as an Excel spreadsheet imported into a MySQL database, this class should help.

## Dependencies

* PHP 5.3.2 or higher
* MySQL 5.5 or higher
* jQuery

## Dev dependencies

* Composer

## Installation

Use composer to bring this into your PHP project. The composer.json should look like this:

```
{
    "require": {
        "usdoj/singletablefacets": "dev-master"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/usdoj/singletablefacets.git"
        },
        {
            "type": "vcs",
            "url": "https://github.com/usdoj/csvtomysql.git"
        }
    ]
}
```

After creating a composer.json similar to the above, do a "composer install".

## Usage

To use the library you need to include the autoloader, and then instantiate the object referencing your configuration file. (See below in the Configuration section for more information about this file.) For example:

```
require_once '/path/to/my/libraries/singletablefacets/vendor/autoload.php';
$configFile = '/path/to/my/configurations/singletablefacets/singletablefacets.yml';
$app = new \USDOJ\SingleTableFacets\AppWeb($configFile);
```

After that, you simply render the various parts of the system wherever you would like them to appear on the page. For example:
```
<div class="my-facets">
 <?php print $app->renderFacets(); ?>
</div>
```

The rendering methods available are:

* renderStyles()
* renderJavascript()
* renderFacets()
* renderKeywordSearch()
* renderResults()
* renderPager()

## Database table

It is up to you to create the database table that you will be using. Some notes:

1. The column names of the database should be the same as the headers (first row) that will be in the Excel/CSV source file.
2. At least one column must be set in MySQL as a unique index. If the data does not naturally have any unique columns, add an auto-increment column to the database and set it as a unique index.
3. For keyword searches you must add a column to the database. This columns should be able to hold a lot of text. (Recommend using the "longtext" column type.) The name of the column must be `stf_keywords`.
4. The `stf_keywords` column mentioned above, as well as any other columns that you would like to include in the keyword search, must belong to a FULLTEXT index on the table. Note that this has ramifications about the storage engine the table uses: on MySQL 5.6 or higher, you can use InnoDB or MyISAM, but for MySQL 5.5 you must use MyISAM.
5. You must create a second FULLTEXT index on the table, just as in #4, except that it does not include the `stf_keywords` column.`
6. Any columns you want to render as dates must be of the DATETIME type.

## Importing source data

The library includes a command-line tool for re-importing data from a CSV file. That tool can be run with:
```
./vendor/bin/singletablefacets [path-to-config-file] [path-to-source-data]
```
Note that the source data file must be a CSV file.

Tip: You'll probably usually be getting the CSV file from an XLS file. Since Excel has a problem with special characters, a useful command-line tool is "xls2csv" from the "catdoc" library. To install:

* Linux: `apt-get install catdoc`
* Babun: `pact install catdoc`

When using xls2csv, to ensure you don't get encoding issues, specify the destination encoding like so:
```
xls2csv -d utf-8 file.xls > file-utf-8.csv
```

## Configuration

The library depends on configuration in a separate YAML file. See singletablefacets.yml.dist for defaults on all the possible settings.

## Scale limits

Because this solution relies on MySQL's FULLTEXT capabilities, it should scale reasonably well. A Solr implementation would surely perform better though, and might make a good future improvement.
