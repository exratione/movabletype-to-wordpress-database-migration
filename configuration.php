<?php

/**
 * Edit the configuration and customizable functions as needed.
 */

// Absolute path to the Medoo database framework library.
$absolute_path_to_medoo = realpath(dirname(__FILE__) . '/medoo.1.0.2.php');

// Default timezone for dates in both databases. See:
// http://php.net/manual/en/timezones.php
$timezone = 'US/Central';

// Date format for datetime strings returned from the Movable Type database.
$date_format = "Y-m-d H:i:s";

// How large a batch of rows to bring over at once. Smaller batches mean a
// longer migration but less memory required for this process to run. Adjust
// smaller for blogs with larger posts, larger for blogs with smaller posts.
$batch_size = 100;

// The MT blog IDs to bring over. In a single blog setup for MT 4 or 5, this
// will be 1. Vanilla WordPress has no notion of multiple blogs, so everything
// will be dropped into one blog if you are bringing over multiple blogs. It is
// probably best in that situation to migrate one by one to different WordPress
// installations.
$mt_blog_ids = [1];

// Connection to Movable Type database.
$mt_connection_config = [
  'database_type' => 'mysql',
  'database_name' => 'example_mt',
  'server' => 'localhost',
  'username' => 'root',
  'password' => 'password',
  // Movable Type wrote UTF-8 bytes into latin1 tables, but this may or may not
  // be the case for later installations where someone cared enough to switch to
  // utf8.
  'charset' => 'latin1',
];

// Connection to WordPress database.
$wp_connection_config =[
  'database_type' => 'mysql',
  'database_name' => 'example_wp',
  'server' => 'localhost',
  'username' => 'root',
  'password' => 'password',
  // WordPress will be using UTF-8. Tables in MySQL will be using utf8 or
  // utf8mb4 if a more recent version.
  'charset' => 'utf8',
];

/**
 * Unfortunately Movable Type installations can have the GUID for posts and
 * pages defined pretty much anywhere in their templates, and in arbitrary ways.
 * It isn't found in the database.
 *
 * So a function must be provided to obtain the GUID to pass to WordPress, where
 * it is stored in the database.
 *
 * It is important to preserve the GUIDs of posts and pages, as changing them
 * will generally cause undesirable behavior in feed readers and the like.
 *
 * Alter the body of this function as needed.
 *
 * @param Array $entry Data for a Moveable Type entry.
 * @return String The GUID for this entry.
 */
function generateGuid($entry) {
  // This is common for older Movable Type installations.
  return $entry["entry_id"] . "@https://www.example.com/";

  // More recent ones use the entry permalink. This is one possible format for
  // such a permalink, but there are many variations. This should be a good
  // enough example to build on.
  /*
  return "https://www.example.com"
    . date('/Y/m/', $row["entry_created_on"])
    . mb_ereg_replace("_", "-", $row["entry_basename"])
    . "/";
  */
}

/**
 * Convert Movable Type post content into HTML for WordPress.
 *
 * The $convert parameter can have the following values, with __default__ being
 * the most common, and in many ways the most annoying to convert.
 *
 * '0' - No formatting. The post is probably already HTML.
 * '__default__' - Convert line breaks to paragraphs.
 * 'markdown' - Post is markdown.
 * 'markdown_with_smartypants' - Post is markdown.
 * 'richtext' - A rich text format.
 * 'textile_2' - Uses the Textile format.
 *
 * @param String $entry_text The entry text.
 * @param String $entry_text_more Additional entry text.
 * @param String $convert The setting for formatting.
 * @return String HTML for WordPress.
 */
function generatePostContent($entry_text, $entry_text_more, $convert) {
  $content = $entry_text;
  if ($entry_text_more) {
    $content .= "\n\n" . $entry_text_more;
  }

  if ($convert == '0') {
    return $content;
  }
  // Line breaks become paragraphs. The MT algorithm isn't great at this, and
  // it is a thorny proposition in posts with a lot of HTML mixed in as well.
  else if ($convert == '__default__') {
    return convertLineBreaks($content);
  }
  // TODO: processors for other content types.
  else {
    return $content;
  }
}
