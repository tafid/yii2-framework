<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\web;

use Yii;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\web\JqueryAsset;
use yii\web\AssetBundle;
use yii\widgets\Block;
use yii\widgets\ContentDecorator;
use yii\widgets\FragmentCache;
use yii\base\InvalidConfigException;

/**
 * View represents a view object in the MVC pattern.
 *
 * View provides a set of methods (e.g. [[render()]]) for rendering purpose.
 *
 * @property \yii\web\AssetManager $assetManager The asset manager. Defaults to the "assetManager" application
 * component.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class View extends \yii\base\View
{
	const EVENT_BEGIN_BODY = 'beginBody';
	/**
	 * @event Event an event that is triggered by [[endBody()]].
	 */
	const EVENT_END_BODY = 'endBody';

	/**
	 * The location of registered JavaScript code block or files.
	 * This means the location is in the head section.
	 */
	const POS_HEAD = 1;
	/**
	 * The location of registered JavaScript code block or files.
	 * This means the location is at the beginning of the body section.
	 */
	const POS_BEGIN = 2;
	/**
	 * The location of registered JavaScript code block or files.
	 * This means the location is at the end of the body section.
	 */
	const POS_END = 3;
	/**
	 * The location of registered JavaScript code block.
	 * This means the JavaScript code block will be enclosed within `jQuery(document).ready()`.
	 */
	const POS_READY = 4;
	/**
	 * This is internally used as the placeholder for receiving the content registered for the head section.
	 */
	const PH_HEAD = '<![CDATA[YII-BLOCK-HEAD]]>';
	/**
	 * This is internally used as the placeholder for receiving the content registered for the beginning of the body section.
	 */
	const PH_BODY_BEGIN = '<![CDATA[YII-BLOCK-BODY-BEGIN]]>';
	/**
	 * This is internally used as the placeholder for receiving the content registered for the end of the body section.
	 */
	const PH_BODY_END = '<![CDATA[YII-BLOCK-BODY-END]]>';

	/**
	 * @var AssetBundle[] list of the registered asset bundles. The keys are the bundle names, and the values
	 * are the registered [[AssetBundle]] objects.
	 * @see registerAssetBundle()
	 */
	public $assetBundles = [];
	/**
	 * @var string the page title
	 */
	public $title;
	/**
	 * @var array the registered meta tags.
	 * @see registerMetaTag()
	 */
	public $metaTags;
	/**
	 * @var array the registered link tags.
	 * @see registerLinkTag()
	 */
	public $linkTags;
	/**
	 * @var array the registered CSS code blocks.
	 * @see registerCss()
	 */
	public $css;
	/**
	 * @var array the registered CSS files.
	 * @see registerCssFile()
	 */
	public $cssFiles;
	/**
	 * @var array the registered JS code blocks
	 * @see registerJs()
	 */
	public $js;
	/**
	 * @var array the registered JS files.
	 * @see registerJsFile()
	 */
	public $jsFiles;

	private $_assetManager;

	/**
	 * Registers the asset manager being used by this view object.
	 * @return \yii\web\AssetManager the asset manager. Defaults to the "assetManager" application component.
	 */
	public function getAssetManager()
	{
		return $this->_assetManager ?: Yii::$app->getAssetManager();
	}

	/**
	 * Sets the asset manager.
	 * @param \yii\web\AssetManager $value the asset manager
	 */
	public function setAssetManager($value)
	{
		$this->_assetManager = $value;
	}

	/**
	 * Marks the ending of an HTML page.
	 */
	public function endPage()
	{
		$this->trigger(self::EVENT_END_PAGE);

		$content = ob_get_clean();
		foreach (array_keys($this->assetBundles) as $bundle) {
			$this->registerAssetFiles($bundle);
		}
		echo strtr($content, [
			self::PH_HEAD => $this->renderHeadHtml(),
			self::PH_BODY_BEGIN => $this->renderBodyBeginHtml(),
			self::PH_BODY_END => $this->renderBodyEndHtml(),
		]);

		unset(
			$this->metaTags,
			$this->linkTags,
			$this->css,
			$this->cssFiles,
			$this->js,
			$this->jsFiles
		);
	}

	/**
	 * Registers all files provided by an asset bundle including depending bundles files.
	 * Removes a bundle from [[assetBundles]] once files are registered.
	 * @param string $name name of the bundle to register
	 */
	private function registerAssetFiles($name)
	{
		if (!isset($this->assetBundles[$name])) {
			return;
		}
		$bundle = $this->assetBundles[$name];
		foreach ($bundle->depends as $dep) {
			$this->registerAssetFiles($dep);
		}
		$bundle->registerAssetFiles($this);
		unset($this->assetBundles[$name]);
	}

	/**
	 * Marks the beginning of an HTML body section.
	 */
	public function beginBody()
	{
		echo self::PH_BODY_BEGIN;
		$this->trigger(self::EVENT_BEGIN_BODY);
	}

	/**
	 * Marks the ending of an HTML body section.
	 */
	public function endBody()
	{
		$this->trigger(self::EVENT_END_BODY);
		echo self::PH_BODY_END;
	}

	/**
	 * Marks the position of an HTML head section.
	 */
	public function head()
	{
		echo self::PH_HEAD;
	}

	/**
	 * Registers the named asset bundle.
	 * All dependent asset bundles will be registered.
	 * @param string $name the name of the asset bundle.
	 * @param integer|null $position if set, this forces a minimum position for javascript files.
	 * This will adjust depending assets javascript file position or fail if requirement can not be met.
	 * If this is null, asset bundles position settings will not be changed.
	 * See [[registerJsFile]] for more details on javascript position.
	 * @return AssetBundle the registered asset bundle instance
	 * @throws InvalidConfigException if the asset bundle does not exist or a circular dependency is detected
	 */
	public function registerAssetBundle($name, $position = null)
	{
		if (!isset($this->assetBundles[$name])) {
			$am = $this->getAssetManager();
			$bundle = $am->getBundle($name);
			$this->assetBundles[$name] = false;
			// register dependencies
			$pos = isset($bundle->jsOptions['position']) ? $bundle->jsOptions['position'] : null;
			foreach ($bundle->depends as $dep) {
				$this->registerAssetBundle($dep, $pos);
			}
			$this->assetBundles[$name] = $bundle;
		} elseif ($this->assetBundles[$name] === false) {
			throw new InvalidConfigException("A circular dependency is detected for bundle '$name'.");
		} else {
			$bundle = $this->assetBundles[$name];
		}

		if ($position !== null) {
			$pos = isset($bundle->jsOptions['position']) ? $bundle->jsOptions['position'] : null;
			if ($pos === null) {
				$bundle->jsOptions['position'] = $pos = $position;
			} elseif ($pos > $position) {
				throw new InvalidConfigException("An asset bundle that depends on '$name' has a higher javascript file position configured than '$name'.");
			}
			// update position for all dependencies
			foreach ($bundle->depends as $dep) {
				$this->registerAssetBundle($dep, $pos);
			}
		}
		return $bundle;
	}

	/**
	 * Registers a meta tag.
	 * @param array $options the HTML attributes for the meta tag.
	 * @param string $key the key that identifies the meta tag. If two meta tags are registered
	 * with the same key, the latter will overwrite the former. If this is null, the new meta tag
	 * will be appended to the existing ones.
	 */
	public function registerMetaTag($options, $key = null)
	{
		if ($key === null) {
			$this->metaTags[] = Html::tag('meta', '', $options);
		} else {
			$this->metaTags[$key] = Html::tag('meta', '', $options);
		}
	}

	/**
	 * Registers a link tag.
	 * @param array $options the HTML attributes for the link tag.
	 * @param string $key the key that identifies the link tag. If two link tags are registered
	 * with the same key, the latter will overwrite the former. If this is null, the new link tag
	 * will be appended to the existing ones.
	 */
	public function registerLinkTag($options, $key = null)
	{
		if ($key === null) {
			$this->linkTags[] = Html::tag('link', '', $options);
		} else {
			$this->linkTags[$key] = Html::tag('link', '', $options);
		}
	}

	/**
	 * Registers a CSS code block.
	 * @param string $css the CSS code block to be registered
	 * @param array $options the HTML attributes for the style tag.
	 * @param string $key the key that identifies the CSS code block. If null, it will use
	 * $css as the key. If two CSS code blocks are registered with the same key, the latter
	 * will overwrite the former.
	 */
	public function registerCss($css, $options = [], $key = null)
	{
		$key = $key ?: md5($css);
		$this->css[$key] = Html::style($css, $options);
	}

	/**
	 * Registers a CSS file.
	 * @param string $url the CSS file to be registered.
	 * @param array $options the HTML attributes for the link tag.
	 * @param string $key the key that identifies the CSS script file. If null, it will use
	 * $url as the key. If two CSS files are registered with the same key, the latter
	 * will overwrite the former.
	 */
	public function registerCssFile($url, $options = [], $key = null)
	{
		$key = $key ?: $url;
		$this->cssFiles[$key] = Html::cssFile($url, $options);
	}

	/**
	 * Registers a JS code block.
	 * @param string $js the JS code block to be registered
	 * @param integer $position the position at which the JS script tag should be inserted
	 * in a page. The possible values are:
	 *
	 * - [[POS_HEAD]]: in the head section
	 * - [[POS_BEGIN]]: at the beginning of the body section
	 * - [[POS_END]]: at the end of the body section
	 * - [[POS_READY]]: enclosed within jQuery(document).ready(). This is the default value.
	 *   Note that by using this position, the method will automatically register the jQuery js file.
	 *
	 * @param string $key the key that identifies the JS code block. If null, it will use
	 * $js as the key. If two JS code blocks are registered with the same key, the latter
	 * will overwrite the former.
	 */
	public function registerJs($js, $position = self::POS_READY, $key = null)
	{
		$key = $key ?: md5($js);
		$this->js[$position][$key] = $js;
		if ($position === self::POS_READY) {
			JqueryAsset::register($this);
		}
	}

	/**
	 * Registers a JS file.
	 * Please note that when this file depends on other JS files to be registered before,
	 * for example jQuery, you should use [[registerAssetBundle]] instead.
	 * @param string $url the JS file to be registered.
	 * @param array $options the HTML attributes for the script tag. A special option
	 * named "position" is supported which specifies where the JS script tag should be inserted
	 * in a page. The possible values of "position" are:
	 *
	 * - [[POS_HEAD]]: in the head section
	 * - [[POS_BEGIN]]: at the beginning of the body section
	 * - [[POS_END]]: at the end of the body section. This is the default value.
	 *
	 * @param string $key the key that identifies the JS script file. If null, it will use
	 * $url as the key. If two JS files are registered with the same key, the latter
	 * will overwrite the former.
	 */
	public function registerJsFile($url, $options = [], $key = null)
	{
		$position = isset($options['position']) ? $options['position'] : self::POS_END;
		unset($options['position']);
		$key = $key ?: $url;
		$this->jsFiles[$position][$key] = Html::jsFile($url, $options);
	}

	/**
	 * Renders the content to be inserted in the head section.
	 * The content is rendered using the registered meta tags, link tags, CSS/JS code blocks and files.
	 * @return string the rendered content
	 */
	protected function renderHeadHtml()
	{
		$lines = [];
		if (!empty($this->metaTags)) {
			$lines[] = implode("\n", $this->metaTags);
		}

		$request = Yii::$app->getRequest();
		if ($request instanceof \yii\web\Request && $request->enableCsrfValidation) {
			$lines[] = Html::tag('meta', '', ['name' => 'csrf-var', 'content' => $request->csrfVar]);
			$lines[] = Html::tag('meta', '', ['name' => 'csrf-token', 'content' => $request->getCsrfToken()]);
		}

		if (!empty($this->linkTags)) {
			$lines[] = implode("\n", $this->linkTags);
		}
		if (!empty($this->cssFiles)) {
			$lines[] = implode("\n", $this->cssFiles);
		}
		if (!empty($this->css)) {
			$lines[] = implode("\n", $this->css);
		}
		if (!empty($this->jsFiles[self::POS_HEAD])) {
			$lines[] = implode("\n", $this->jsFiles[self::POS_HEAD]);
		}
		if (!empty($this->js[self::POS_HEAD])) {
			$lines[] = Html::script(implode("\n", $this->js[self::POS_HEAD]), ['type' => 'text/javascript']);
		}
		return empty($lines) ? '' : implode("\n", $lines);
	}

	/**
	 * Renders the content to be inserted at the beginning of the body section.
	 * The content is rendered using the registered JS code blocks and files.
	 * @return string the rendered content
	 */
	protected function renderBodyBeginHtml()
	{
		$lines = [];
		if (!empty($this->jsFiles[self::POS_BEGIN])) {
			$lines[] = implode("\n", $this->jsFiles[self::POS_BEGIN]);
		}
		if (!empty($this->js[self::POS_BEGIN])) {
			$lines[] = Html::script(implode("\n", $this->js[self::POS_BEGIN]), ['type' => 'text/javascript']);
		}
		return empty($lines) ? '' : implode("\n", $lines);
	}

	/**
	 * Renders the content to be inserted at the end of the body section.
	 * The content is rendered using the registered JS code blocks and files.
	 * @return string the rendered content
	 */
	protected function renderBodyEndHtml()
	{
		$lines = [];
		if (!empty($this->jsFiles[self::POS_END])) {
			$lines[] = implode("\n", $this->jsFiles[self::POS_END]);
		}
		if (!empty($this->js[self::POS_END])) {
			$lines[] = Html::script(implode("\n", $this->js[self::POS_END]), ['type' => 'text/javascript']);
		}
		if (!empty($this->js[self::POS_READY])) {
			$js = "jQuery(document).ready(function(){\n" . implode("\n", $this->js[self::POS_READY]) . "\n});";
			$lines[] = Html::script($js, ['type' => 'text/javascript']);
		}
		return empty($lines) ? '' : implode("\n", $lines);
	}
}
