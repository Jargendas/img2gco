<?php
/**
 * Flip (mirror) an image left to right.
 *
 * @param image  resource
 * @param x      int
 * @param y      int
 * @param width  int
 * @param height int
 * @return bool
 * @require PHP 3.0.7 (function_exists), GD1
 */
function flipMyImage(&$image, $x = 0, $y = 0, $width = null, $height = null) {
    if ($width  < 1) $width  = imagesx($image);
    if ($height < 1) $height = imagesy($image);
    // Truecolor provides better results, if possible.
    if (function_exists('imageistruecolor') && imageistruecolor($image)) {
        $tmp = imagecreatetruecolor($width, 1);
    } else {
        $tmp = imagecreate($width, 1);
    }

    $y2 = $y + $height - 1;
    for ($i = (int) floor(($height - 1) / 2); $i >= 0; $i--) {
        // Backup bottom stripe.
        imagecopy($tmp, $image, 0, 0, $x, $y2 - $i, $width, 1);
        // Copy top stripe to the bottom.
        imagecopy($image, $image, $x, $y2 - $i, $x, $y + $i, $width, 1);
        // Copy backuped bottom stripe to the top.
        imagecopy($image, $tmp, $x, $y + $i, 0, 0, $width, 1);
    }
    imagedestroy($tmp);
    return true;
}

function map($value, $fromLow, $fromHigh, $toLow, $toHigh) {
    $fromRange = $fromHigh - $fromLow;
    $toRange = $toHigh - $toLow;
    $scaleFactor = $toRange / $fromRange;

    // Re-zero the value within the from range
    $tmpValue = $value - $fromLow;
    // Rescale the value to the to range
    $tmpValue *= $scaleFactor;
    // Re-zero back to the to range
    return $tmpValue + $toLow;
}

set_time_limit(300);

//error_reporting(E_ALL);
//ini_set('display_errors', 1);

$now = new DateTime();
$name = $now->getTimestamp();           // Unix Timestamp -- Since PHP 5.3;
$ext = "jpg";
if (isset($_FILES['image']['name'])) {
    $saveto = "$name.$ext";
    //print ";$saveto\n";
    //move_uploaded_file($_FILES['image']['tmp_name'], $saveto);
    $typeok = TRUE;
    switch($_FILES['image']['type']) {
        //case "image/gif": $src = imagecreatefromgif($saveto); break;
        //case "image/jpeg": // Both regular and progressive jpegs
        //case "image/pjpeg": $src = imagecreatefromjpeg($saveto); break;
        //case "image/png": $src = imagecreatefrompng($saveto); break;
        
        case "image/gif": $src = imagecreatefromgif($_FILES['image']['tmp_name']); break;
        case "image/jpeg": // Both regular and progressive jpegs
        case "image/pjpeg": $src = imagecreatefromjpeg($_FILES['image']['tmp_name']); break;
        case "image/png": $src = imagecreatefrompng($_FILES['image']['tmp_name']); break;
        default: $typeok = FALSE; break;
    }
    if ($typeok) { 
       //print ";image OK\n";
       //list($w, $h) = getimagesize($saveto);
       list($w, $h) = getimagesize($_FILES['image']['tmp_name']);
       
    }
    else
      exit();
}
else
   exit();

if(!isset($_POST['sizeY']) || $_POST['sizeY'] == 0) { 
   print("No image height defined :(\n");
   exit();
}

//header('Content-Type: text/plain; charset=utf-8');


$laserMax=$_POST['LaserMax'];//$laserMax=65; //out of 255
$laserMin=$_POST['LaserMin']; //$laserMin=20; //out of 255
$laserOff=$_POST['LaserOff'];//$laserOff=13; //out of 255
$whiteLevel=$_POST['whiteLevel'];

$feedRate = $_POST['feedRate'];//$feedRate = 800; //in mm/sec
$travelRate = $_POST['travelRate'];//$travelRate = 3000;

$overScan = $_POST['overScan'];//$overScan = 3;

$offsetY=$_POST['offsetY'];//$offsetY=10;
$sizeY=$_POST['sizeY'];//$sizeY=40;
$scanGap=$_POST['scanGap'];//$scanGap=.1;

$offsetX=$_POST['offsetX'];//$offsetX=5;
$sizeX=$sizeY*$w/$h; //SET A HEIGHT AND CALC WIDTH (this should be customizable)
$resX=$_POST['resX'];;//$resX=.1;

//Create a resampled image with exactly the data needed, 1px in to 1px out
$pixelsX = round($sizeX/$resX);
$pixelsY = round($sizeY/$scanGap);

$tmp = imagecreatetruecolor($pixelsX, $pixelsY);
imagecopyresampled($tmp, $src, 0, 0, 0, 0, $pixelsX, $pixelsY, $w, $h);
flipMyImage($tmp);
imagefilter($tmp, IMG_FILTER_GRAYSCALE);

if (isset($_POST['blackAndWhite']))
   imagefilter($tmp, IMG_FILTER_CONTRAST, -255);

if($_POST['preview'] == 1) {
   header('Content-Type: image/png'); //do this to display following image
   imagepng($tmp);
   imagedestroy($tmp);
   imagedestroy($src);        
   exit(); //exit if above
}

header("Content-Type: text/gcode");
header("Content-Disposition: attachment; filename=".explode(".", $_FILES['image']['name'])[0].".gcode");

print("\n;Created using Nebarnix's IMG2GCO program Ver 1.0\n");
print(";http://nebarnix.com 2015\n");

print(";Size in pixels X=$pixelsX, Y=$pixelsY\n");
print(";Size in mm X=".round($sizeX,2).", Y=".round($sizeY,2)."\n");
$cmdRate = round(($feedRate/$resX)*2/60);
print(";Speed is $feedRate mm/min, $resX mm/pix => $cmdRate lines/sec\n");
print(";Power is $laserMin to $laserMax (". round($laserMin/255*100,1) ."%-". round($laserMax/255*100,1) ."%)\n");

print("G28 X Y\n");
print("G90\n");
print("G21\n");
print("M106 S$laserOff; Turn laser off\n");
$prevValue = $laserOff; //Clear out the 'previous value' comparison
$prevX = 0;

//loop through the lines
$lineIndex=0;
for($line=$offsetY; $line<($sizeY+$offsetY) && $lineIndex < $pixelsY; $line+=$scanGap) {  
   //analyze the row and find first and last nonwhite pixels
   $firstX = -1; //initialize to impossible value
   $lastX = -1; //initialize to impossible value
   for($pixelIndex=0; $pixelIndex < $pixelsX; $pixelIndex++) {
      $rgb = imagecolorat($tmp,$pixelIndex,$lineIndex); //Grab the pixel color
      $value = ($rgb >> 16) & 0xFF; //Convert to 8 bit value
      if($value < $whiteLevel) { //If image data (IE nonwhite)
         if($firstX == -1) //mark this as the first nonwhite pixel
            $firstX = $pixelIndex;
         
         $lastX = $pixelIndex; //Track the last seen nonwhite pixel
      }
   }

   //if there are no Nonwhite pixels we can just skip this line altogether
   if($lastX < 0 || $firstX < 0) {
      print("; Line $lineIndex Skipped\n");
      $lineIndex++; //Next line GO!
      continue;
   }

   if (abs($offsetX + $firstX*$resX - $prevX) < abs($offsetX + $lastX*$resX - $prevX)) {
      $pixelIndex = $firstX; //Start at the first nonwhite pixel
      $lastX++;
      $direction = 1;
      $pixel = $offsetX + $pixelIndex * $resX;
   } else {
      $pixelIndex = $lastX;
      $lastX = $firstX-1;
      $firstX = $pixelIndex;
      $direction = -1;
      $pixel = $offsetX + ($pixelIndex + 1) * $resX;
   }

   while (true) {

      //abort the loop if there are no more nonwhite pixels
      if($pixelIndex == $lastX) {
         if ($overScan == 0) {
            print("M106 S".$prevValue."\n"); //Write out the laser value
            print("G1 X".round($pixel,4)." F".$feedRate."\n"); //Continue moving
         }
         print(";Skip The Rest\n");
         break;
      }

      //If this is the first nonwhite pixel we have to move to the correct line, remembering the overscan offset
      if($pixelIndex == $firstX) {            
         print("G1 X".round($pixel-$direction*$overScan,4)." Y".round($line,4)." F$travelRate\n"); //travel quickly to the line start position
         print("G1 F$feedRate\n"); //Set travel speed to the right speed for etching
      }
         
      if ($overScan != 0) {
         print("G1 X".round($pixel,4)."\n"); //Continue moving
      }

      $rgb = imagecolorat($tmp,$pixelIndex,$lineIndex); //grab the pixel value
      $value = ($rgb >> 16) & 0xFF; //convert pixel to 8bit color

      if (isset($_POST['blackAndWhite'])) {
         if ($value <= $whiteLevel) {
            $value = 0;
         } else {
            $value = 255;
         }
      }

      $value = round(map($value,255,0,$laserMin,$laserMax),1); //map 8bit range to our laser range

      if($value != $prevValue) { //Is the laser power different? no need to send the same power again
         if ($overScan == 0) {
            if ($pixelIndex != $firstX) {
               print("M106 S".$prevValue."\n"); //Write out the laser value
            }
            if ($prevValue == 0) {
                  if ($pixelIndex != $firstX) {
                     print("G0 X".round($pixel,4)." F".$travelRate."\n");
                  }
            } else {
                  print("G1 X".round($pixel,4)." F".$feedRate."\n"); //Continue moving
            }
         } else {
            print("M106 S".$value."\n"); //Write out the laser value
         }
      }
      $prevValue = $value; //Save the laser power for the next loop

      $prevX = round($pixel, 4);
      $pixel += $resX * $direction;

      $pixelIndex += $direction; //Next pixel GO!
   }
   print("M106 S$laserOff;\n\n"); //Turn off the power for the re-trace
   $prevValue = $laserOff; //Clear out the 'previous value' comparison 
   $lineIndex++; //Next line GO!
}
$lineIndex--; //Undo one for debugging porpoises

print("M106 S$laserOff ;Turn laser off\n");
print("G0 X$offsetX Y$offsetY F$travelRate ;Go home to start position\n");
imagedestroy($tmp);
?>
