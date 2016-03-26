<?php
/**
 * PHP command line script to migrate data from a Movable Type database to a
 * WordPress database.
 *
 * - Uses Medoo for database access.
 * - Requires mbstring.
 * - Requires configuration: edit the Definitions section of this script before
 *   running it.
 *
 * This maintains the IDs of most entities being migrated, all except MT assets
 * to WP attachments, which is necessary to make a lot of other things easier
 * when migrating platforms.
 *
 * META TABLES
 *
 * This script adds very minimal data to meta tables (wp_termmeta, wp_postmeta,
 * wp_usermeta, wp_commentmeta) so it is a good idea to run this migration
 * before setting up a new WordPress installation with plugins, configuration,
 * etc.
 *
 * POSTS AND PAGES
 *
 * Content is converted to HTML for the default Movable Type line break to
 * paragraph formatting. Less common formatting options such as Markdown and
 * Textile are not converted to HTML. Some additions would be required for that,
 * and can be made in the generatePostContent() function.
 *
 * AUTHORS AND COMMENTERS
 *
 * This only migrates over authors, not registered commenters. The comments are
 * migrated, retaining all the display data, user names, etc, but are
 * disconnected from any actual registered commenter in the database. Movable
 * Type and WordPress can both handle registered commenters in such a wide
 * variety of ways that a generic system to migrate them seems impractical.
 *
 * Author passwords are not migrated; they will have to be reset manually.
 *
 * Author permissions are similarly not migrated and will be have to be rebuilt
 * manually. All authors are created as admins in WordPress regardless of the
 * original MT permissions.
 *
 * TRACKBACKS AND PINGS
 *
 * Neither trackbacks nor pings are migrated.
 *
 * ASSETS
 *
 * Asset files must be manually moved over. MT assets are converted to WP
 * attachments, but asset tags are not migrated.
 *
 * If the file location changes, as is likely, then the GUID for all of the
 * attachment records will also need to be updated.
 */

// --------------------------------------------------------------------------
// Load the configuration and Medoo database framework.
// --------------------------------------------------------------------------

require realpath(dirname(__FILE__) . '/configuration.php');
require $absolute_path_to_medoo;

// --------------------------------------------------------------------------
// Database connections.
// --------------------------------------------------------------------------

// Connection to Movable Type database.
$mt_db = new medoo($mt_connection_config);

// Connection to WordPress database.
$wp_db = new medoo($wp_connection_config);

// --------------------------------------------------------------------------
// Set defaults.
// --------------------------------------------------------------------------

date_default_timezone_set($timezone);

// Set the text encoding. Movable Type writes UTF-8 into latin1 data tables,
// but that should be handled by specifying UTF-8 liberally as the encoding,
// and using Medoo as the data layer interface.
mb_internal_encoding("UTF-8");

// --------------------------------------------------------------------------
// Functions copied from WordPress 4.* wp-includes/formatting.php
// --------------------------------------------------------------------------

// Easier to copy the few functions we need than to try to load specific files,
// since you then need to load their dependencies, and that quickly gets out of
// hand. To go that path, this would really have to be reworked as a WordPress
// plugin.

require realpath(dirname(__FILE__) . '/wordpress.functions.php');

// --------------------------------------------------------------------------
// Functions reimplemented from Movable Type.
// --------------------------------------------------------------------------

require realpath(dirname(__FILE__) . '/movabletype.functions.php');

// --------------------------------------------------------------------------
// Common functionality.
// --------------------------------------------------------------------------

/**
 * Throw if the most recent query resulted in an error.
 *
 * @param Mixed $db Database interface.
 */
function throw_on_db_failure($db) {
  $error = $db->error();
  if (sizeof($error) && $error[2] != null) {
    throw new Exception($error[2]);
  }
}

/**
 * Delete table contents.
 *
 * @param Mixed $db Database interface.
 * @param String $table Table name.
 */
function delete_from_table($db, $table) {
  $db->delete($table, []);
  throw_on_db_failure($db);
}

/**
 * Select table contents.
 *
 * @param Mixed $db Database interface.
 * @param String $table Table name.
 * @param Array $fields Fields to select.
 * @param Array $fields Where clause.
 */
function select_from_table($db, $table, $fields, $where) {
  return $db->select($table, $fields, $where);
  throw_on_db_failure($db);
}

/**
 * Insert table contents.
 *
 * @param Mixed $db Database interface.
 * @param String $table Table name.
 * @param Array $rows Rows to insert.
 */
function insert_into_table($db, $table, $rows) {
  $db->insert($table, $rows);
  throw_on_db_failure($db);
}

/**
 * Run a data transfer in batches.
 *
 * @param String $id_column_name The Movable Type table numeric ID column name.
 * @param String $delete_fn Name of the function to delete WordPress table contents.
 * @param String $select_fn Name of the function to select from Movable Type table contents.
 * @param String $insert_fn Name of the function to insert into the WordPress table.
 */
function run($id_column_name, $delete_fn, $select_fn, $insert_fn) {
  global $mt_db, $wp_db, $batch_size;
  $last_id = 0;
  $complete = false;

  call_user_func($delete_fn);

  while (!$complete) {
    $results = call_user_func($select_fn, $last_id);
    //$results = transcode_results($results);

    if (sizeof($results) > 0) {
      $last_row = end(array_values($results));
      $last_id = $last_row[$id_column_name];

      call_user_func($insert_fn, $results);
    }

    if (sizeof($results) < $batch_size) {
      $complete = true;
    }
  }
}

// --------------------------------------------------------------------------
// Categories.
// --------------------------------------------------------------------------

function category_delete() {
  global $wp_db;

  delete_from_table($wp_db, "wp_term_taxonomy");
  delete_from_table($wp_db, "wp_termmeta");
  delete_from_table($wp_db, "wp_terms");
}

function category_select($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  return select_from_table($mt_db, "mt_category", [
    "category_basename",
    "category_description",
    "category_id",
    "category_label",
    "category_parent"
  ], [
    "AND" => [
      "category_blog_id" => $mt_blog_ids,
      "category_id[>]" => $last_id
    ],
    "ORDER" => "category_id ASC",
    "LIMIT" => $batch_size
  ]);
}

function category_insert($rows) {
  global $wp_db;

  // -----------------------------------------------------------------------
  // wp_terms table.
  // -----------------------------------------------------------------------

  $term_rows = array_map(function ($row) {
    return [
      "name" => $row["category_label"],
      "slug" => mb_ereg_replace("_", "-", $row["category_basename"]),
      // Default.
      "term_group" => 0,
      "term_id" => $row["category_id"]
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_terms", $term_rows);

  // -----------------------------------------------------------------------
  // wp_term_taxonomy table.
  // -----------------------------------------------------------------------

  $term_taxonomy_rows = array_map(function ($row) {
    $parent_category_id = 0;
    if (!empty($row["category_parent"])) {
      $parent_category_id = $row["category_parent"];
    }

    $description = "";
    if (!empty($row["category_description"])) {
      $description = $row["category_description"];
    }

    return [
      // The count of posts is filled in later, after the posts are migrated.
      "count" => 0,
      // Category description may be null in MT, but can't be in WP.
      "description" => $description,
      "parent" => $parent_category_id,
      "taxonomy" => "category",
      "term_id" => $row["category_id"],
      // Since we have one row here per term, may as well use the same ID.
      "term_taxonomy_id" => $row["category_id"]
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_term_taxonomy", $term_taxonomy_rows);
}

run("category_id", "category_delete", "category_select", "category_insert");

// --------------------------------------------------------------------------
// Authors.
// --------------------------------------------------------------------------

// The mt_author table includes registered commenters as well as the blog owners
// and authors. These users are not brought over in this implementation.
//
// We distinguish between these user types by the permissions they have in the
// mt_permission table:
// https://movabletype.org/documentation/man/MT/Permission.html

function user_delete() {
  global $wp_db;

  delete_from_table($wp_db, "wp_usermeta");
  delete_from_table($wp_db, "wp_users");
}

// We are only selecting users with permissions to administer or author posts.
// This excludes registered commenters, who will not be brought over. That is a
// much bigger can of worms given the many ways in which MT can be configured to
// handle registered commenters.
function user_select($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  $imploded_blog_ids = implode(", ", $mt_blog_ids);

  // Note the lack of escaping here - all parameters are under our control.
  $query = implode(" ", [
    "select",
    "a.author_auth_type,",
    "a.author_created_on,",
    "a.author_email,",
    "a.author_name,",
    "a.author_nickname,",
    "a.author_id,",
    // Not interesting.
    //. "a.author_is_superuser,",
    "a.author_name,",
    // Not trying to bring over the password. It will have to be manually reset.
    // . "a.author_password,",
    "a.author_url",
    "from mt_author a",
    "where",
    "a.author_id > '{$last_id}'",
    "and",
    "exists (",
      "select 1 from mt_permission",
      "where permission_blog_id in ({$imploded_blog_ids})",
      "and (",
        "permission_permissions like '%administer%'",
        "or",
        "permission_permissions like '%create_post%'",
      ")",
    ")",
    "order by a.author_id asc",
    "limit {$batch_size}",
  ]);

  $result = $mt_db->query($query)->fetchAll();
  throw_on_db_failure($mt_db);
  return $result;
}

function user_insert($rows) {
  global $wp_db;

  $user_rows = array_map(function ($row) {
    $lc_name = mb_convert_case($row["author_name"], MB_CASE_LOWER);

    return [
      "display_name" => $row["author_nickname"],
      "id" => $row["author_id"],
      "user_email" => $row["author_email"],
      "user_login" => $lc_name,
      "user_nicename" => sanitize_title_with_dashes($lc_name),
      // We are not trying to bring over the password. It will have to be reset
      // via other means.
      "user_pass" => "",
      "user_registered" => $row["author_created_on"],
      // This is apparently a dead field, not really used.
      //"user_status" => 0,
      "user_url" => $row["author_url"]
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_users", $user_rows);

  // All users are given the minimal meta configuration to be administrators.
  // TODO: examination of MT permissions to do better than this.
  $usermeta_rows_capabilities = array_map(function ($row) {
    return [
      "user_id" => $row["author_id"],
      "meta_key" => "wp_capabilities",
      "meta_value" => "a:1:{s:13:\"administrator\";s:1:\"1\";}"
    ];
  }, $rows);
  $usermeta_rows_level = array_map(function ($row) {
    return [
      "user_id" => $row["author_id"],
      "meta_key" => "wp_user_level",
      "meta_value" => "10"
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_usermeta", $usermeta_rows_capabilities);
  insert_into_table($wp_db, "wp_usermeta", $usermeta_rows_level);
}

run("author_id", "user_delete", "user_select", "user_insert");

// --------------------------------------------------------------------------
// Pages and Posts - stored in the same table in Movable Type and WordPress.
// --------------------------------------------------------------------------

// Since pages are being done first, we're going to use this as the opportunity
// to clear out the whole of the wp_posts table rather than try to be clever
// about deleting it piecemeal and by type.
//
// This gets rid of everything: revisions, attachments, and nav menu items in
// addition to posts and pages. This has to be done to ensure that IDs can be
// preserved.
//
// See: https://codex.wordpress.org/Post_Types
function post_delete() {
  global $wp_db;

  delete_from_table($wp_db, "wp_postmeta");
  delete_from_table($wp_db, "wp_posts");
}

function post_select($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  return select_from_table($mt_db, "mt_entry", [
    "entry_allow_comments",
    "entry_allow_pings",
    // Not useful.
    //"entry_atom_id",
    "entry_author_id",
    // Datetime. Not using it.
    // "entry_authored_on",
    // The slug used to generate post URL.
    "entry_basename",
    // Unused field. Remnant of older schema.
    //"entry_category_id",
    "entry_comment_count",
    // Datetime.
    "entry_created_on",
    // "entry" or "page".
    "entry_class",
    // Formatting of post. "0", __default__", or other values. Fun.
    "entry_convert_breaks",
    "entry_excerpt",
    "entry_id",
    // Datetime.
    "entry_modified_on",
    "entry_pinged_urls",
    // Numeric. 1 = Draft, 2 = Published, 3 = Review, 4 = Future, 5 = Junk
    "entry_status",
    "entry_text",
    "entry_text_more",
    "entry_title",
    "entry_to_ping_urls"
  ], [
    "AND" => [
      "entry_blog_id" => $mt_blog_ids,
      "entry_id[>]" => $last_id
    ],
    "ORDER" => "entry_id ASC",
    "LIMIT" => $batch_size
  ]);
}

function post_insert($rows) {
  global $wp_db;

  $post_rows = array_map(function ($row) {
    global $date_format;

    // Map MT entry_allow_comments to WP comment_status.
    $comment_status = [
      0 => "closed",
      1 => "open"
    ];

    // Map MT entry_allow_pings to WP ping_status.
    $ping_status = [
      0 => "closed",
      1 => "open"
    ];

    // Map MT entry_class to WP post_type.
    $post_type = [
      "entry" => "post",
      "page" => "page"
    ];

    // Map MT entry_status to WP post_status.
    $post_status = [
      1 => "draft",
      2 => "publish",
      3 => "pending",
      4 => "future",
      5 => "trash"
    ];

    return [
      "comment_count" => $row["entry_comment_count"],
      "comment_status" => $comment_status[$row["entry_allow_comments"]],
      // This absolutely must be the same as the GUID used by MT, as otherwise
      // feed readers will not be able to keep track of posts.
      "guid" => generateGuid($row),
      "id" => $row["entry_id"],
      // MT entries don't have menu order.
      "menu_order" => 0,
      "ping_status" => $ping_status[$row["entry_allow_comments"]],
      "pinged" => "",
      "post_author" => $row["entry_author_id"],
      "post_content" => generatePostContent(
        $row["entry_text"],
        $row["entry_text_more"],
        $row["entry_convert_breaks"]
      ),
      // Used by plugins, not used by core WordPress.
      "post_content_filtered" => "",
      // Datetimes.
      "post_date" => date($date_format, strtotime($row["entry_created_on"])),
      "post_date_gmt" => gmdate($date_format, strtotime($row["entry_created_on"])),
      "post_excerpt" => $row["entry_excerpt"],
      // Datetimes.
      "post_modified" => date($date_format, strtotime($row["entry_modified_on"])),
      "post_modified_gmt" => gmdate($date_format, strtotime($row["entry_modified_on"])),
      // Posts and pages will have an empty string for this value. It is
      // intended for attachments.
      "post_mime_type" => "",
      // The slug for the post permalink. Important that this preserves the slug
      // for MT to allow the URLs to be consistent.
      "post_name" => mb_ereg_replace("_", "-", $row["entry_basename"]),
      // MT entries don't have parents.
      "post_parent" => 0,
      // MT entries don't have passwords.
      "post_password" => "",
      // "publish", "future", "draft", "pending", "private", "trash",
      // "auto-draft", "inherit". Only some of these are relevant.
      "post_status" => $post_status[$row["entry_status"]],
      "post_title" => $row["entry_title"],
      "post_type" => $post_type[$row["entry_class"]],
      "to_ping" => ""
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_posts", $post_rows);
}

run("entry_id", "post_delete", "post_select", "post_insert");

// --------------------------------------------------------------------------
// Post categories.
// --------------------------------------------------------------------------

// Category assignments are stored in mt_placement in Movable Type, and in
// wp_term_relationships in WordPress.

function post_categories_delete() {
  global $wp_db;

  delete_from_table($wp_db, "wp_term_relationships");
}

function post_categories_select($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  return select_from_table($mt_db, "mt_placement", [
    "placement_category_id",
    "placement_entry_id",
    "placement_id",
    // Not really relevant to migration.
    //"placement_is_primary"
  ], [
    "AND" => [
      "placement_blog_id" => $mt_blog_ids,
      "placement_id[>]" => $last_id
    ],
    "ORDER" => "placement_id ASC",
    "LIMIT" => $batch_size
  ]);
}

function post_categories_insert($rows) {
  global $wp_db;

  $post_categories_rows = array_map(function ($row) {
    return [
      // The post ID.
      "object_id" => $row["placement_entry_id"],
      "term_taxonomy_id" => $row["placement_category_id"]
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_term_relationships", $post_categories_rows);
}

run(
  "placement_id",
  "post_categories_delete",
  "post_categories_select",
  "post_categories_insert"
);

// --------------------------------------------------------------------------
// Set wp_term_taxonomy counts.
// --------------------------------------------------------------------------

// Now that we have posts, we can sort out the wp_term_taxonomy.count field,
// which counts the number of posts with a given taxonomy term assigned to them.
$counts_query = implode(" ", [
  "select count(1) as count, term_taxonomy_id",
  "from wp_term_relationships group by term_taxonomy_id"
]);
$count_rows = $wp_db->query($counts_query)->fetchAll();

array_walk($count_rows, function ($row) {
  global $wp_db;

  $wp_db->update("wp_term_taxonomy", [
    "count" => $row["count"]
  ], [
    "term_taxonomy_id" => $row["term_taxonomy_id"]
  ]);
});

// --------------------------------------------------------------------------
// Comments.
// --------------------------------------------------------------------------

// The wp_comments table contains 'comment', 'trackback', 'pingback' types, but
// delete them all here.
function comments_delete() {
  global $wp_db;

  delete_from_table($wp_db, "wp_comments");
  delete_from_table($wp_db, "wp_commentmeta");
}

function comments_select($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  return select_from_table($mt_db, "mt_comment", [
    "comment_author",
    // Only populated for registered commenters, with an entry in the mt_author
    // table. Since we're not bringing over those users, we can skip this.
    //"comment_commenter_id",
    // Datetime.
    "comment_created_on",
    "comment_email",
    "comment_entry_id",
    "comment_id",
    "comment_ip",
    // Datetime.
    "comment_modified_on",
    "comment_parent_id",
    // This may be plain text or a limited subset of HTML.
    "comment_text",
    "comment_url",
    // Invisible comments are usually going to be junk, but may be pending
    // approval.
    "comment_visible"
  ], [
    "AND" => [
      "comment_blog_id" => $mt_blog_ids,
      "comment_id[>]" => $last_id
    ],
    "ORDER" => "comment_id ASC",
    "LIMIT" => $batch_size
  ]);
}

function comments_insert($rows) {
  global $wp_db;

  $comments_rows = array_map(function ($row) {
    global $date_format;

    // Can be null in MT, can't be null in WP.
    $comment_parent = 0;
    if ($row["comment_parent_id"] != null) {
      $comment_parent = $row["comment_parent_id"];
    }

    return [
      // Agent string for user browser. No data for this in MT.
      "comment_agent" => "",
      // comment_visible should be 0 or 1 in MT, and thus translate over
      // directly.
      "comment_approved" => $row["comment_visible"],
      "comment_author" => $row["comment_author"],
      "comment_author_email" => $row["comment_email"],
      "comment_author_ip" => $row["comment_ip"],
      "comment_author_url" => $row["comment_url"],
      "comment_content" => $row["comment_text"],
      // In theory both sides should be using the same timezone, so we can move
      // this over directly, since both MT and WP use datetime columns.
      "comment_date" => $row["comment_created_on"],
      // This one requires conversion, though.
      "comment_date_gmt" => gmdate($date_format, strtotime($row["comment_created_on"])),
      "comment_id" => $row["comment_id"],
      "comment_karma" => 0,
      "comment_parent" => $comment_parent,
      // No comment structure is bring brought over.
      "comment_post_id" => $row["comment_entry_id"],
      "comment_type" => "comment",
      // We're not migrating comment authors, so no user_id.
      "user_id" => 0
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_comments", $comments_rows);
}

run(
  "comment_id",
  "comments_delete",
  "comments_select",
  "comments_insert"
);

// --------------------------------------------------------------------------
// Trackbacks and Pings.
// --------------------------------------------------------------------------

// TODO. Does anyone use these any more? They are spam vectors and little else.
//
// If implemented for a migration these will be pulled from the MT mt_trackback
// and mt_tbping tables and placed into the wp_comments table with appropriate
// values for the comment_type.

// --------------------------------------------------------------------------
// Assets.
// --------------------------------------------------------------------------

// Movable type assets in mt_asset are migrated to WordPress attachments in
// wp_posts. Migrating the actual files is not in the scope of this task.
//
// If the file location changes, as is likely, then the GUID for all of the
// attachment records will also need to be updated.
//
// Asset tags are not migrated, though in theory we could construct categories
// based on those tags and assign categories.

// Attachments are in the posts table, and have already been deleted.
function assets_delete() {}

function assets_select ($last_id) {
  global $mt_db, $mt_blog_ids, $batch_size;

  return select_from_table($mt_db, "mt_asset", [
    "asset_class",
    // User ID.
    "asset_created_by",
    // Datetime.
    "asset_created_on",
    "asset_description",
    "asset_file_ext",
    "asset_file_name",
    "asset_file_path",
    "asset_id",
    "asset_label",
    "asset_mime_type",
    // User ID.
    "asset_modified_by",
    // Datetime.
    "asset_modified_on",
    "asset_parent",
    "asset_url"
  ], [
    "AND" => [
      "asset_blog_id" => $mt_blog_ids,
      "asset_id[>]" => $last_id
    ],
    "ORDER" => "asset_id ASC",
    "LIMIT" => $batch_size
  ]);
}

function assets_insert($rows) {
  global $wp_db, $date_format;

  $comments_rows = array_map(function ($row) {
    return [
      "comment_count" => 0,
      "comment_status" => "closed",
      // Use the asset URL as the GUID.
      "guid" => $row["asset_url"],
      // Let the ID get set by autoincrement.
      //"id" => ...,
      // MT assets don't have menu order.
      "menu_order" => 0,
      "ping_status" => "closed",
      "pinged" => "",
      "post_author" => $row["asset_created_by"],
      "post_content" => $row["asset_description"],
      // Used by plugins, not used by core WordPress.
      "post_content_filtered" => "",
      // Datetimes.
      "post_date" => $row["asset_created_on"],
      "post_date_gmt" => gmdate($date_format, strtotime($row["asset_created_on"])),
      "post_excerpt" => $row["entry_excerpt"],
      // Datetimes.
      "post_modified" => $row["asset_modified_on"],
      "post_modified_gmt" => gmdate($date_format, strtotime($row["asset_modified_on"])),
      "post_mime_type" => $row["asset_mime_type"],
      // The slug for the post permalink. Derive something appropriate from the
      // asset title.
      "post_name" => mb_ereg_replace("[_\s]+", "-", $row["asset_label"]),
      // MT entries don't have parents.
      "post_parent" => 0,
      // MT entries don't have passwords.
      "post_password" => "",
      "post_status" => "inherit",
      "post_title" => $row["asset_label"],
      "post_type" => "attachment",
      "to_ping" => ""
    ];
  }, $rows);

  insert_into_table($wp_db, "wp_posts", $comments_rows);
}

run(
  "asset_id",
  "assets_delete",
  "assets_select",
  "assets_insert"
);
