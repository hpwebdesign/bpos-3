<?php
namespace Bpos;

// Code 39 for digits, drawn as an inline SVG (receipt order number)
class Barcode {
	const MAP = array(
		'0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
		'5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', '*' => 'nwnnwnwnn'
	);

	public static function svg($digits, $height = 36) {
		$digits = preg_replace('/\D/', '', (string)$digits);

		if ($digits === '') {
			return '';
		}

		$x = 0;
		$bars = '';

		foreach (str_split('*' . $digits . '*') as $i => $char) {
			if ($i > 0) {
				$x += 1;
			}

			foreach (str_split(self::MAP[$char]) as $k => $w) {
				$width = $w === 'w' ? 3 : 1;

				if ($k % 2 === 0) {
					$bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . (int)$height . '"/>';
				}

				$x += $width;
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . (int)$height . '" width="100%" height="' . (int)$height . '" preserveAspectRatio="none" fill="#000" role="img" aria-label="' . htmlspecialchars($digits, ENT_QUOTES) . '">' . $bars . '</svg>';
	}
}
