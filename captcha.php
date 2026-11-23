<?php
// The captcha picture on the login and sign-up forms: <img src="captcha.php">
//
// Makes a random 5 character code, keeps it in the session (never sent to the browser as text)
// and draws it as an SVG picture: every letter is built from small squares of a pixel font,
// tilted and moved a little, with random lines and dots on top. A program reading the page
// can't just copy the code - it would have to read the picture, like a person does.
include("db.php");

// Letters that are easy to tell apart (no 0/O, 1/I, 5/S, 2/Z, 8/B)
const CAPTCHA_FONT = [
    'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
    'C' => ['01110', '10001', '10000', '10000', '10000', '10001', '01110'],
    'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
    'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
    'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
    'G' => ['01110', '10001', '10000', '10111', '10001', '10001', '01111'],
    'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
    'J' => ['00111', '00010', '00010', '00010', '00010', '10010', '01100'],
    'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
    'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
    'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
    'N' => ['10001', '10001', '11001', '10101', '10011', '10001', '10001'],
    'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
    'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
    'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
    'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
    'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
    'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'],
    'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
    'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
    '3' => ['11111', '00010', '00100', '00010', '00001', '10001', '01110'],
    '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
    '6' => ['00110', '01000', '10000', '11110', '10001', '10001', '01110'],
    '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
    '9' => ['01110', '10001', '10001', '01111', '00001', '00010', '01100'],
];

// A new random code each time the picture loads (random_int is cryptographically random)
$letters = array_keys(CAPTCHA_FONT);
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $letters[random_int(0, count($letters) - 1)];
}
$_SESSION['captcha'] = ['code' => $code, 'time' => time()];

$width = 200;
$height = 64;
$cell = 5; // size of one pixel of the font
$colours = ['#d42a1c', '#b0200f', '#3f3f46', '#18181b'];

$svg = "<svg xmlns='http://www.w3.org/2000/svg' width='$width' height='$height' viewBox='0 0 $width $height'>";
$svg .= "<rect width='100%' height='100%' fill='#fafafa'/>";

// Faint lines behind the letters
for ($i = 0; $i < 6; $i++) {
    $svg .= sprintf("<line x1='%d' y1='%d' x2='%d' y2='%d' stroke='#d4d4d8' stroke-width='%d'/>",
        random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), random_int(1, 3));
}

// The letters: each one a group of small squares, tilted and moved a bit
foreach (str_split($code) as $n => $letter) {
    $x = 12 + $n * 37 + random_int(-2, 2);
    $y = 13 + random_int(-5, 5);
    $angle = random_int(-20, 20);
    $colour = $colours[random_int(0, count($colours) - 1)];
    $svg .= "<g transform='translate($x $y) rotate($angle 12 17)' fill='$colour'>";
    foreach (CAPTCHA_FONT[$letter] as $row => $bits) {
        foreach (str_split($bits) as $col => $bit) {
            if ($bit === '1') {
                // Each square is placed slightly off the grid so the letter isn't perfectly regular
                $svg .= sprintf("<rect x='%.1f' y='%.1f' width='%d' height='%d'/>",
                    $col * $cell + random_int(-6, 6) / 10, $row * $cell + random_int(-6, 6) / 10, $cell, $cell);
            }
        }
    }
    $svg .= "</g>";
}

// Lines and dots on top, the same size as the letter squares, so the letters can't simply be cut out
for ($i = 0; $i < 2; $i++) {
    $svg .= sprintf("<path d='M%d %d Q%d %d %d %d' stroke='%s' stroke-width='1.5' fill='none'/>",
        0, random_int(10, $height - 10), random_int(40, $width - 40), random_int(0, $height), $width, random_int(10, $height - 10),
        $colours[random_int(0, count($colours) - 1)]);
}
for ($i = 0; $i < 40; $i++) {
    $svg .= sprintf("<rect x='%d' y='%d' width='%d' height='%d' fill='%s' opacity='0.6'/>",
        random_int(0, $width), random_int(0, $height), random_int(1, 3), random_int(1, 3), $colours[random_int(0, 3)]);
}
$svg .= "</svg>";

// Never cache it: every load must be a new code
header('Content-Type: image/svg+xml');
header('Cache-Control: no-store, max-age=0');
echo $svg;
