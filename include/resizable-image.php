<?php

/**
 * Class to help resize the images.
 */
class ResizableImage
{
	private $path;
	private $info;

	public function __construct($path)
	{
		$this->path = $path;
		$this->info = getimagesize($this->path);
	}

	/**
	 * Creates a new image that is a copy of the current one,
	 *  resized to fit in the specified box.
	 * @param width The (maximum) width of the new image.
	 * @param height The (maximum) height of the new image.
	 * @param keepRatio Whether or not to preserve the current aspect ratio of the image.
	 * @returns A handle to the new image, or null if it couldn't be created.
	 */
	public function createResizedImage($width, $height, $keepRatio = True)
	{
		if ($keepRatio === True)
		{
			$newSize = $this->getSizeInBox($width, $height);
			$height = $newSize['height'];
			$width = $newSize['width'];
		}

		$newImage = imagecreatetruecolor($width, $height);
		imagealphablending($newImage, false);
		imagesavealpha($newImage, true);
		$curImage = $this->loadFromFile();

		// can go no further if we don't have the resources.
		if ($curImage === null || $newImage === null)
		{
			imagedestroy($curImage);
			throw new Exception('Could not load or create image.', E_INTERNAL_ERROR);
		}

		$res = imagecopyresampled($newImage, $curImage, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
		imagedestroy($curImage);
		if (!$res)
		{
			throw new Exception('Could not resize image.', E_INTERNAL_ERROR);
		}

		return $newImage;
	}

	/**
	 * Convenience wrapper which saves a resized copy into the target file.
	 * @param width The (maximum) width of the new image.
	 * @param height The (maximum) height of the new image.
	 * @param dest The path to save the image into.
	 */
	public function resizeInto($width, $height, $dest)
	{
		$newImageResource = $this->createResizedImage($width, $height);
		imagepng($newImageResource, $dest);
		imagedestroy($newImageResource);
	}

	/**
	 * Convenience function that delegates to self::fitInBox.
	 * See that function for full details.
	 */
	private function getSizeInBox($boxWidth, $boxHeight)
	{
		$w = $this->getWidth();
		$h = $this->getHeight();
		$ret = self::fitInBox($boxWidth, $boxHeight, $w, $h);
		return $ret;
	}

	/**
	 * Load the image from the file.
	 * Note that while this uses the file extension to determine the file type,
	 *  no validation is done regarding the availability of the image handling functions.
	 * @returns a handle to the resource of the image, or null if it could not be loaded.
	 */
	private function loadFromFile()
	{
		$resource = null;
		$mime = $this->getMIME();
		if ($mime == 'image/png')
		{
			$resource = imagecreatefrompng($this->path);
		}
		elseif ($mime == 'image/gif')
		{
			$resource = imagecreatefromgif($this->path);
		}
		elseif ($mime == 'image/jpeg')
		{
			$resource = imagecreatefromjpeg($this->path);
		}
		return $resource;
	}

	/**
	 * Get the MIME type of the image.
	 */
	function getMIME()
	{
		return $this->info['mime'];
	}

	/**
	 * Get the height of the image, in pixels.
	 */
	function getHeight()
	{
		return $this->info[1];
	}

	/**
	 * Get the width of the image, in pixels.
	 */
	function getWidth()
	{
		return $this->info[0];
	}

	/**
	 * Static method that determines the resized size of an image based on a box to resize it into.
	 * @param boxWidth The width of the box to fit the dimensions in
	 * @param boxHeight The height of the box to fit the dimensions in
	 * @param origWidth The width of the item to be resized
	 * @param origHeight The height of the item to be resized
	 * @returns An array with keys 'width' and 'height' both guaranteed
	 * to be less than their respective dimension of the fit box,
	 * but in the same ratio as the original width & height.
	 */
	public static function fitInBox($boxWidth, $boxHeight, $origWidth, $origHeight)
	{
		$boxRatio = $boxWidth / $boxHeight;
		$origRatio = $origWidth / $origHeight;

		if ($boxRatio > $origRatio)
		{
			// the height is the determining dimension
			$h = $boxHeight;
			$w = $boxHeight * $origRatio;
		}
		else
		{
			// the width is the determining dimension
			$w = $boxWidth;
			$h = $boxWidth / $origRatio;
		}

		if ($w > $origWidth || $h > $origHeight) {
			// Don't make images bigger than they already are
			$h = $origHeight;
			$w = $origWidth;
		}

		assert($w <= $boxWidth); // width too large
		assert($h <= $boxHeight); // height too large

		$ret = array('width' => $w, 'height' => $h);
		return $ret;
	}
}
