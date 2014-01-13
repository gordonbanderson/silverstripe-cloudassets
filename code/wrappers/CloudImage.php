<?php
/**
 * Wraps the Image object. We have to replace a few more methods
 * with Image because things like formatting and dimensions don't
 * work the same with a placeholder file.
 *
 * @author Mark Guinn <mark@adaircreative.com>
 * @date 01.10.2014
 * @package cloudassets
 * @subpackage wrappers
 */
class CloudImage extends Image implements CloudAssetInterface
{
	function Link() {
		return $this->CloudStatus == 'Live' ? $this->getCloudURL() : parent::Link();
	}

	function RelativeLink() {
		return $this->CloudStatus == 'Live' ? $this->getCloudURL() : parent::RelativeLink();
	}

	function getURL() {
		return $this->CloudStatus == 'Live' ? $this->getCloudURL() : parent::getURL();
	}

	function getAbsoluteURL() {
		return $this->CloudStatus == 'Live' ? $this->getCloudURL() : parent::getAbsoluteURL();
	}

	function getAbsoluteSize() {
		return $this->CloudStatus == 'Live' ? $this->CloudSize : parent::getAbsoluteSize();
	}


	/**
	 * Save the dimensions before we potentially wipe out the file
	 */
	function onBeforeCloudPut() {
		$this->setCloudMeta('Dimensions', $this->getDimensions('live'));
	}


	/**
	 * @param string|int $dim - 'string' or 0 (width) or 1 (height)
	 * @return int|string
	 */
	function getDimensions($dim = "string") {
		// give an option to get the real dimensions
		if ($dim === 'live') return parent::getDimensions();
		if ($this->CloudStatus != 'Live') return parent::getDimensions($dim);

		// otherwise we need to resort to stored dimensions because the
		// file may be in the cloud and the local may be a placeholder
		$val = $this->getCloudMeta('Dimensions');
		if ($dim === 'string') return $val;

		$val = explode('x', $val);
		return $val[$dim];
	}


	/**
	 * Return an image object representing the image in the given format.
	 * This image will be generated using generateFormattedImage().
	 * The generated image is cached, to flush the cache append ?flush=1 to your URL.
	 *
	 * Just pass the correct number of parameters expected by the working function
	 *
	 * @param string $format The name of the format.
	 * @return Image_Cached
	 */
	public function getFormattedImage($format) {
		$args = func_get_args();

		if($this->ID && $this->Filename && Director::fileExists($this->Filename)) {
			$cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);

			if(!file_exists(Director::baseFolder()."/".$cacheFile) || isset($_GET['flush'])) {
				if ($this->CloudStatus === 'Live' && $this->containsPlaceholder()) {
					$this->downloadFromCloud();
					call_user_func_array(array($this, "generateFormattedImage"), $args);
					$this->convertToPlaceholder();
				} else {
					call_user_func_array(array($this, "generateFormattedImage"), $args);
				}
			}

			$cached = new CloudImage_Cached($cacheFile);
			// Pass through the title so the templates can use it
			$cached->Title = $this->Title;
			// Pass through the parent, to store cached images in correct folder.
			$cached->ParentID = $this->ParentID;

			// upload to cloud if needed
			$cached->updateCloudStatus();
			return $cached;
		}
	}


	/**
	 * Remove all of the formatted cached images for this image.
	 *
	 * @return int The number of formatted images deleted
	 */
	public function deleteFormattedImages() {
		if(!$this->Filename) return 0;
		$bucket = $this->getCloudBucket();

		$numDeleted = 0;
		$methodNames = $this->allMethodNames(true);
		$cachedFiles = array();

		$folder = $this->ParentID ? $this->Parent()->Filename : ASSETS_DIR . '/';
		$cacheDir = Director::getAbsFile($folder . '_resampled/');

		if(is_dir($cacheDir)) {
			if($handle = opendir($cacheDir)) {
				while(($file = readdir($handle)) !== false) {
					// ignore all entries starting with a dot
					if(substr($file, 0, 1) != '.' && is_file($cacheDir . $file)) {
						$cachedFiles[] = $file;
					}
				}
				closedir($handle);
			}
		}

		$generateFuncs = array();
		foreach($methodNames as $methodName) {
			if(substr($methodName, 0, 8) == 'generate') {
				$format = substr($methodName, 8);
				$generateFuncs[] = preg_quote($format);
			}
		}
		// All generate functions may appear any number of times in the image cache name.
		$generateFuncs = implode('|', $generateFuncs);
		$pattern = "/^(({$generateFuncs})\d+\-)+" . preg_quote($this->Name) . "$/i";

		foreach($cachedFiles as $cfile) {
			if(preg_match($pattern, $cfile)) {
				if(Director::fileExists($cacheDir . $cfile)) {
					unlink($cacheDir . $cfile);
					$numDeleted++;

					if ($bucket && $this->CloudStatus === 'Live') $bucket->delete($folder . '_resampled/' . $cfile);
				}
			}
		}

		return $numDeleted;
	}

}