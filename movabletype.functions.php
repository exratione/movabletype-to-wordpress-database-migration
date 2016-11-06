<?php
/**
 * Movable Type Perl functions implemented in PHP.
 */

/**
 * Implement the Movable Type convert line breaks function. This is found in
 * lib/MT/Util.pm::html_text_transform.
 *
 * @return String Content with line breaks converted to <p></p> tags.
 */
function convertLineBreaks($content) {
  /* The Perl implementation.
  sub html_text_transform {
      my $str = shift;
      $str = '' unless defined $str;
      my @paras = split /\r?\n\r?\n/, $str;
      for my $p (@paras) {
          if ($p !~ m@^</?(?:h1|h2|h3|h4|h5|h6|table|ol|dl|ul|menu|dir|p|pre|center|form|fieldset|select|blockquote|address|div|hr)@) {
              $p =~ s!\r?\n!<br />\n!g;
              $p = "<p>$p</p>";
          }
      }
      join "\n\n", @paras;
  }
  */

  $paragraphs = mb_split("(\r?\n){2,}", $content);
  $paragraphs = array_map(function ($paragraph) {
    // Regular expression to check to see what we have here at the start of a
    // paragraph. If it starts with an HTML element, then don't wrap it.
    if (preg_match(
        "/^</?(?:h1|h2|h3|h4|h5|h6|table|ol|dl|ul|menu|dir|p|pre|center|form|fieldset|select|blockquote|address|div|hr)/ui",
        $paragraph
    )) {
      return $paragraph;
    }
    else {
      $paragraph = preg_replace('/\r?\n/u', "<br/>\n", $paragraph);
      return "<p>" . $paragraph . "</p>";
    }
  }, $paragraphs);

  return implode("\n\n", $paragraphs);
}
