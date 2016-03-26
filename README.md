# Movable Type 4/5.* to WordPress 4.* Database Migration

The PHP script provided here can be used to migrate the contents of a Movable
Type 4.* or (probably) 5.* database to a WordPress 4.* database, with some
caveats and limitations.

  * Faster than the few available [alternative methods][1].
  * Can migrate very large numbers of posts and comments.
  * Batches up inserts for efficiency.
  * Preserves IDs for comments, posts, and pages.
  * Can be set to preserve the post GUID used in feeds.
  * Movable Type asset records are converted to WordPress attachment records.

## Requirements

  * A recent version of PHP, 5.4 or later.
  * Access to both the old Movable Type database and the new WordPress database.
  * The [PHP CLI][4].
  * [WP-CLI][2], as you will need it to [update user passwords][3] following
migration.
  * The [Medoo database framework][5] library.

## Preparation

The WordPress installation should be clean, without any plugins installed or
content added. Many plugins add metadata for posts, comments, and pages, and
this migration script cannot account for that. Install the desired plugins
afterwards.

Copy the Medoo database framework library to an accessible location, and update
`configuration.php` to set the absolute path to that library.

Update `configuration.php` with the appropriate values for your migration. Some
of the configuration involves function definitions. The defaults will work for
most Movable Type installations, but if you were using some of the less usual
content options, such as Textile, you will have to write code to translate the
content into HTML.

## Running the Migration

```
php migrate.php
```

Then use WP-CLI to set the password for one of the migrated users, and log in to
check the results.

You will have to set passwords and permissions for all migrated users, as they
are all given adminstrative permissions, and none will have passwords set at the
outset.

Asset files will have to be copied over separately. If their location changes,
their records will have to be updated to reflect that.

## Limitations

  * Only tested on Movable Type 4.* and WordPress 4.*.
  * User records for post and page authors are migrated, but not commenters.
  * All migrated users are made administrators in WordPress.
  * Passwords are not migrated. They must be reset for authors using WP-CLI or
similar methods.
  * Trackbacks and pings are not migrated.
  * Markdown, Textile 2, and Rich Text formats are not supported. You must
extend `generatePostContent` to convert that content to HTML for WordPress.
  * Movable Type allowed arbitrary GUID formats to be defined in templates, so
editing the `generateGuid` function will probably be necessary.
  * Movable Type asset IDs are not preserved in conversion to WordPress
attachments.
  * If the location of asset files changes, all of their corresponding
attachment record GUIDs will need to be regenerated.

[1]: https://www.exratione.com/2015/03/notes-on-exporting-large-movable-type-databases/
[2]: http://wp-cli.org/
[3]: http://wp-cli.org/commands/user/update/
[4]: http://php.net/manual/en/features.commandline.php
[5]: http://medoo.in/
