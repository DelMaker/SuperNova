<?php

if (!defined('INSIDE')) { die(); }

// The example captain module uses legacy query helpers absent in this core.
// Preserve existing implementations when another version provides them.
if (!function_exists('doSelectFetch')) {
  function doSelectFetch($query) {
    return SN::$db->doquery($query, true);
  }
}
if (!function_exists('doUpdate')) {
  function doUpdate($query) {
    return SN::$db->doquery($query);
  }
}
if (!function_exists('doInsert')) {
  function doInsert($query) {
    return SN::$db->doquery($query);
  }
}
