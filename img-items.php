<?php
use League\ColorExtractor\Color;
use League\ColorExtractor\Palette;
use Spatie\Color\Distance;
use Spatie\Color\Factory;
use Spatie\Color\Rgb;

function img_items($image, $options = array()) {
  function get_average_luminance($image, $samples = 30) {
    $width = imagesx($image);
    $height = imagesy($image);
    $x_step = intval($width / $samples);
    $y_step = intval($height / $samples);
    $total_luminance = 0;
    $sample_count = 1;

    for ($y = 0; $y < $height; $y += $y_step) {
      for ($x = 0; $x < $width; $x += $x_step) {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $luminance = ($r + $r + $b + $g + $g + $g) / 6;
        $total_luminance += $luminance;
        $sample_count++;
      }
    }

    $average_luminance = $total_luminance / $sample_count;

    return ($average_luminance / 255) * 100;
  }

  function get_pixel_color($image, $x, $y) {
    $rgb_data = imagecolorat($image, $x, $y);
    $r = ($rgb_data >> 16) & 0xFF;
    $g = ($rgb_data >> 8) & 0xFF;
    $b = $rgb_data & 0xFF;
    return new Rgb($r, $g, $b);
  }

  function is_background_color($color, $bg_colors, $threshold) {
    foreach($bg_colors as $bg_color) {
      if (strval($color) === strval($bg_color)) return true;
      if (!$threshold) continue;
      $distance = Distance::CIE76($color, $bg_color);
      if ($distance <= $threshold) return true;
    }

    return false;
  }

  $image_data_type = gettype($image);

  if (($image_data_type === 'resource' && get_resource_type($image) === 'gd') || ($image_data_type === 'object' && get_class($image) === 'GdImage')) {
    $img = $image;
  } elseif ($image_data_type === 'string') {
    $contents = file_get_contents($image);
    $img = imagecreatefromstring($contents);
  } else {
    trigger_error('$image parameter is an invalid type. Must be a file path or GdImage instance.', E_USER_ERROR);
  }

  $width = imagesx($img);
  $height = imagesy($img);

  $opts = array_merge(array(
    'background'            => 0,
    'background_threshold'  => 5,
    'gap_threshold'         => 5,
    'gap_y_threshold'       => null,
    'gap_x_threshold'       => null,
    'size_threshold'        => 5,
    'height_threshold'      => null,
    'width_threshold'       => null
  ), (array) $options);

  $items = array();

  if ($opts['gap_y_threshold'] === null) $opts['gap_y_threshold'] = $opts['gap_threshold'];
  if ($opts['gap_x_threshold'] === null) $opts['gap_x_threshold'] = $opts['gap_threshold'];
  if ($opts['height_threshold'] === null) $opts['height_threshold'] = $opts['size_threshold'];
  if ($opts['width_threshold'] === null) $opts['width_threshold'] = $opts['size_threshold'];

  if ($opts['background'] === -1) {
    if (get_average_luminance($img) >= 50) {
      $opts['background'] = new Rgb(255, 255, 255);
    } else {
      $opts['background'] = new Rgb(0, 0, 0);
    }
  } elseif ($opts['background'] === 0) {
    $opts['background'] = array(get_pixel_color($img, 0, 0));
  } elseif (is_int($opts['background']) && $opts['background'] >= 1 && $opts['background'] <= 10) {
    $palette = Palette::fromGD($img)->getMostUsedColors($opts['background']);
    $opts['background'] = array_values(array_map(function($i) {
      $rgb = Color::fromIntToRgb($i);
      return new Rgb($rgb['r'], $rgb['g'], $rgb['b']);
    }, $palette));
  }

  if (!is_array($opts['background'])) {
    $opts['background'] = array($opts['background']);
  }

  foreach($opts['background'] as $bg_index => $bg_color) {
    if (gettype($bg_color) === 'string') {
      $color_object = Factory::fromString($bg_color);
      $opts['background'][$bg_index] = $color_object->toRgb();
    }
  }

  for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
      $color = get_pixel_color($img, $x, $y);
      $left_gap = 0;
      $top_gap = 0;
      $found_items = array();

      if (is_background_color($color, $opts['background'], $opts['background_threshold'])) continue;

      for ($i = ($x - 1); $i > ($x - $opts['gap_x_threshold']); $i--) {
        $left_color = null;

        if ($i >= 0) {
          $left_color = get_pixel_color($img, $i, $y);
        }

        if ($i < 0 || is_background_color($left_color, $opts['background'], $opts['background_threshold'])) {
          $left_gap++;
        } else {
          break;
        }
      }

      for ($i = ($y - 1); $i > ($y - $opts['gap_y_threshold']); $i--) {
        $top_color = null;

        if ($i >= 0) {
          $top_color = get_pixel_color($img, $x, $i);
        }

        if ($i < 0 || is_background_color($top_color, $opts['background'], $opts['background_threshold'])) {
          $top_gap++;
        } else {
          break;
        }
      }

      if ($left_gap < $opts['gap_x_threshold'] || $top_gap < $opts['gap_y_threshold']) {
        $l = $x - $left_gap - 1;
        $t = $y - $top_gap - 1;

        $found_items = array_values(array_filter(array_keys($items), function($k) use($items, $opts, $left_gap, $top_gap, $x, $y, $l, $t) {
          $i = $items[$k];

          return (
            ($left_gap < $opts['gap_x_threshold'] && ($l >= $i['left'] && $l <= $i['right'] && $y >= $i['top'] && $y <= $i['bottom'])) ||
            ($top_gap < $opts['gap_y_threshold'] && ($x >= $i['left'] && $x <= $i['right'] && $t >= $i['top'] && $t <= $i['bottom']))
          );
        }));
      }

      if (!empty($found_items)) {
        $item = $items[$found_items[0]];

        if (count($found_items) > 1) {
          for ($i = 1; $i < count($found_items); $i++) {
            $old_item = $items[$found_items[$i]];

            if ($old_item['left'] < $item['left']) $item['left'] = $old_item['left'];
            if ($old_item['top'] < $item['top']) $item['top'] = $old_item['top'];
            if ($old_item['right'] > $item['right']) $item['right'] = $old_item['right'];
            if ($old_item['bottom'] > $item['bottom']) $item['bottom'] = $old_item['bottom'];

            unset($items[$found_items[$i]]);
          }

          $items = array_filter($items);
        }

        if ($x < $item['left']) $item['left'] = $x;
        if ($y < $item['top']) $item['top'] = $y;
        if ($x > $item['right']) $item['right'] = $x;
        if ($y > $item['bottom']) $item['bottom'] = $y;

        $items[$found_items[0]] = $item;
      } else {
        $item = array(
          'left'   => $x,
          'top'    => $y,
          'right'  => $x,
          'bottom' => $y
        );

        $items[] = $item;
      }
    }
  }

  $items = array_values(array_filter(array_map(function($i) {
    $i['width'] = $i['right'] - $i['left'] + 1;
    $i['height'] = $i['bottom'] - $i['top'] + 1;
    return $i;
  }, $items), function($i) use($opts) {
    return ($i['width'] >= $opts['width_threshold'] && $i['height'] >= $opts['height_threshold']);
  }));

  if ($image_data_type === 'string') {
    imagedestroy($img);
  }

  return $items;
}
?>
