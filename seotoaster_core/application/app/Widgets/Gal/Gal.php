<?php

class Widgets_Gal_Gal extends Widgets_Abstract {

	const DEFAULT_THUMB_SIZE = '250';

	private $_websiteHelper  = null;

	protected function  _init() {
		parent::_init();
		$this->_view             = new Zend_View(array('scriptPath' => dirname(__FILE__).'/views'));
		$this->_websiteHelper    = Zend_Controller_Action_HelperBroker::getStaticHelper('website');
		$this->_view->websiteUrl = $this->_websiteHelper->getUrl();
        array_push($this->_cacheTags, __CLASS__);
	}

	protected function  _load() {
		if (!is_array($this->_options)
            || empty($this->_options)
            || !isset($this->_options[0])
            || !$this->_options[0]
            || preg_match('~^\s*$~', $this->_options[0])
        ) {
			throw new Exceptions_SeotoasterException($this->_translator->translate('You should specify folder.'));
		}

		$configHelper        = Zend_Controller_Action_HelperBroker::getStaticHelper('config');
		$path = $this->_websiteHelper->getPath().$this->_websiteHelper->getMedia().$this->_options[0].DIRECTORY_SEPARATOR;
		$mediaServersAllowed = $configHelper->getConfig('mediaServers');
		unset($configHelper);
		$websiteData         = ($mediaServersAllowed) ? Zend_Registry::get('website') : null;
		$useCrop             = isset($this->_options[2]) ? (boolean)$this->_options[2] : false;
		$useCaption          = isset($this->_options[3]) ? (boolean)$this->_options[3] : false;

		if (!is_dir($path)) {
			throw new Exceptions_SeotoasterException($path . ' is not a directory.');
		}

        $pathFileOriginal = $path.Tools_Image_Tools::FOLDER_ORIGINAL.DIRECTORY_SEPARATOR;
		$sourceImages     = Tools_Filesystem_Tools::scanDirectory($pathFileOriginal);
		$galFolder        = $path.(($useCrop) ? Tools_Image_Tools::FOLDER_CROP : Tools_Image_Tools::FOLDER_THUMBNAILS)
            .DIRECTORY_SEPARATOR;

        // Changing the image to fit the size
        if ($useCrop
            && isset($this->_options[4], $this->_options[5])
            && is_numeric($this->_options[4])
            && is_numeric($this->_options[5])
        ) {
            $width      = $this->_options[4];
            $height     = $this->_options[5];
            $galFolder .= $width.'-'.$height.DIRECTORY_SEPARATOR;
        }
        else {
            $width      = isset($this->_options[1]) ? $this->_options[1] : self::DEFAULT_THUMB_SIZE;
            $height     = 'auto';
        }

		if (!is_dir($galFolder)) {
            Tools_Filesystem_Tools::mkDir($galFolder);
		}

        $sourcePart = str_replace($this->_websiteHelper->getPath(), $this->_websiteHelper->getUrl(), $galFolder);
		foreach ($sourceImages as $key => $image) {
            if (is_file($galFolder.$image)) {
                $imgInfo = getimagesize($galFolder.$image);
                if ($imgInfo[0] != $width) {
                    Tools_Image_Tools::resizeByParameters(
                        $pathFileOriginal.$image,
                        $width,
                        $height,
                        !($useCrop),
                        $galFolder,
                        $useCrop
                    );
                }
            }
			else {
                Tools_Image_Tools::resizeByParameters(
                    $pathFileOriginal.$image,
                    $width,
                    $height,
                    !($useCrop),
                    $galFolder,
                    $useCrop
                );
			}

			if ($mediaServersAllowed) {
				$mediaServer     = Tools_Content_Tools::getMediaServer();
				$cleanWebsiteUrl = str_replace('www.', '', $websiteData['url']);
				$sourcePart      = str_replace($websiteData['url'], $mediaServer . '.' . $cleanWebsiteUrl, $sourcePart);
			}
			$sourceImages[$key] = array(
				'path' => $sourcePart.$image,
				'name' => $image
			);
		}

		$this->_view->folder              = $this->_options[0];
		$this->_view->original = str_replace($this->_websiteHelper->getPath(), $this->_websiteHelper->getUrl(), $path)
            .Tools_Image_Tools::FOLDER_ORIGINAL.DIRECTORY_SEPARATOR;
		$this->_view->images              = $sourceImages;
		$this->_view->useCaption          = $useCaption;
		$this->_view->galFolderPath       = $galFolder;
		$this->_view->mediaServersAllowed = $mediaServersAllowed;
		$this->_view->galFolder = str_replace($this->_websiteHelper->getPath(), $this->_websiteHelper->getUrl(), $galFolder);

		return $this->_view->render('gallery.phtml');
	}

	public static function getWidgetMakerContent() {
		$translator    = Zend_Registry::get('Zend_Translate');
		$view          = new Zend_View(array('scriptPath' => dirname(__FILE__).'/views'));
		$websiteHelper = Zend_Controller_Action_HelperBroker::getStaticHelper('website');
		$data          = array(
			'title'   => $translator->translate('Image Gallery'),
			'content' => $view->render('wmcontent.phtml'),
			'icons'   => array($websiteHelper->getUrl().'system/images/widgets/imageGallery.png')
		);
		unset($view, $translator);

		return $data;
	}
}
