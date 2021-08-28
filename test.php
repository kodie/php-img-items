<?php
require_once('vendor/autoload.php');

$test_dir = 'test_output';
$test_image = 'assets/feed-example.png';

if (file_exists($test_dir)) {
  // Clear out $test_dir
  array_map('unlink', array_filter((array) glob("$test_dir/*")));
} else {
  // Ensure $test_dir exists
  mkdir($test_dir, 0777, true);
}

function resize_image($image, $width = null, $height = null, $destroy_og = true) {
  $og_width = imagesx($image);
  $og_height = imagesy($image);
  $ratio = $og_width / $og_height;

  if (!$width) $width = $og_width;
  if (!$height) $height = $og_height;

  if ($width / $height > $ratio) {
    $width = $height * $ratio;
  } else {
    $height = $width / $ratio;
  }

  $img = imagecreatetruecolor($width, $height);

  imagecopyresampled($img, $image, 0, 0, 0, 0, $width, $height, $og_width, $og_height);

  if ($destroy_og) {
    imagedestroy($image);
  }

  return $img;
}

function save_image($image, $file) {
  switch(pathinfo($file, PATHINFO_EXTENSION)) {
    case 'jpg':
    case 'jpeg':
      imagejpeg($image, $file, 0);
      break;
    case 'png':
      imagepng($image, $file, 0);
      break;
  }
}

$contents = file_get_contents($test_image);
$ext = pathinfo($test_image, PATHINFO_EXTENSION);
$img = imagecreatefromstring($contents);
// $img = resize_image($img, imagesx($img) / 4, imagesy($img) / 4); // Optionally shrink image to speed things up

$items = img_items($img, array(
  // 'background'            => 0,
  // 'background_threshold'  => 5,
  // 'gap_threshold'         => 5,
  // 'gap_y_threshold'       => null,
  // 'gap_x_threshold'       => null,
  // 'size_threshold'        => 5,
  // 'height_threshold'      => null,
  // 'width_threshold'       => null
));

print_r($items);

if (count($items)) {
  // Extract all items as images
  foreach($items as $index => $item) {
    $item_img = imagecrop($img, array(
      'x'      => $item['left'],
      'y'      => $item['top'],
      'width'  => $item['width'],
      'height' => $item['height']
    ));

    save_image($item_img, "$test_dir/$index.$ext");
    imagedestroy($item_img);
  }

  // Extract the largest item as an image
  $largest = array_reduce($items, function($c, $i) {
    return (($i['width'] + $i['height']) > ($c['width'] + $c['height'])) ? $i : $c;
  });

  $largest_img = imagecrop($img, array(
    'x'      => $largest['left'],
    'y'      => $largest['top'],
    'width'  => $largest['width'],
    'height' => $largest['height']
  ));

  save_image($largest_img, "$test_dir/largest.$ext");
  imagedestroy($largest_img);

  // Fill in all items
  $filled_img = resize_image($img, null, null, false);
  foreach($items as $item) {
    for ($y = $item['top']; $y < $item['bottom'] + 1; $y++) {
      for ($x = $item['left']; $x < $item['right'] + 1; $x++) {
        $color = imagecolorallocate($filled_img, 255, 0, 0);
        imagesetpixel($filled_img, $x, $y, $color);
      }
    }
  }

  save_image($filled_img, "$test_dir/filled.$ext");
  imagedestroy($filled_img);
}

imagedestroy($img);

// @TODO: Implement better testing
if (count($items) === 50) {
  echo "Test passed.\n";
} else {
  echo "Test failed.\n";
}
?>
