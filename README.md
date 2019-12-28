# Duplimentor

Creates an archive of pages and Elementor templates that can then be imported into an existing WP installation.  Copies all meta data used by Elementor.

### The Problem

Often when developing a new theme we'll take a copy of a site so that we cn check that our changes still work with the existing data.  The traditional way to create a nerw design required you to modify the PHP files in a theme to match your design.  Elementor changes this by storing it's page layouts in the wordpress wp_post table.  The new design does not physically exist in PHP/CSS/JS files, which means you cannot just upload your new theme to a site.  This is even more true if the site is an ecommerce site.  If the site is in use during development then many of the new database records will have the same ID as the new Elementor data records.  We cannot just export the database and import over the top of the site as that could delete data.  In an eCommerce system this could be lost sales data.  

### My Solution

Duplimentor attempts to add or update pages.  The backup archive contains all the details needed to recreate an elementor page.  Each page along with any meta data and images are added to the site as if they had been added by hand.

## Getting started

### To export

    wp duplimentor export


### To import

    wp duplimentor import <<ARCHIVE>>


### Prerequisites

You need to have WP CLI installed

### Installing

Install using WP CLI 
    
    wp package install https://github.com/markdicker/duplimentor.git  


## Authors

- Mark Dicker 

## License

This project is licensed under the GPLv3 License - see the [LICENSE](LICENSE) file for details.

## Acknowledgements

When I get stuck i fall back to google so if there is any code from public help forums such as Stack Overflow, then a big hat tip to those authors.

## Disclaimer

This could break your website so make a backup and don't blindly use it on a production site.  As are most open source projects, it was developed to solve my own need and may not work for you.

