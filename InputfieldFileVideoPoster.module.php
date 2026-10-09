<?php
namespace ProcessWire;

class InputfieldFileVideoPoster extends WireData implements Module, ConfigurableModule
{

	public function __construct()
	{
		// Default configuration
		$this->set('storagePath', '/site/assets/video-covers/');
	}

	public function init()
	{
		// Register the API endpoint for uploading thumbnails
		// We use a root-relative path to avoid conflicts with Admin ProcessController
		$this->addHook('/video-poster/upload/', $this, 'handleUpload');
	}

	public function ready()
	{
		// Load JS only in admin
		if ($this->wire('page')->template == 'admin') {
			$this->addHookAfter('ProcessPageEdit::execute', $this, 'addScripts');
			$this->addHookAfter('InputfieldFile::renderItem', $this, 'hookRenderItem');
		}

		// Add hook to Pagefile to get the cover URL
		$this->addHook('Pagefile::videoCoverUrl', $this, 'hookVideoCoverUrl');
	}

	public function addScripts(HookEvent $event)
	{
		$config = $this->wire('config');
		$info = $this->wire('modules')->getModuleInfo($this);
		$version = $info['version'];

		$config->scripts->add($config->urls->InputfieldFileVideoPoster . 'InputfieldFileVideoPoster.js?v=' . $version);

		// Pass configuration to JS
		$this->wire('config')->js('InputfieldFileVideoPoster', [
			'uploadUrl' => $config->urls->root . 'video-poster/upload/'
		]);
	}

	/**
	 * Helper to generate the toolbar HTML
	 */
	public function renderToolbar($videoUrl, $coverUrl, $basename, $pageId = 0, $autoGenerate = false)
	{
		$pageId = (int) $pageId;
		$basename = $this->wire('sanitizer')->entities($basename);
		$auto = $autoGenerate ? " data-auto='1'" : '';
		$toolbar = "";
		if (!$coverUrl) {
			// Not created yet
			$toolbar = "<div class='video-poster-actions' style='margin-top: 5px; font-size: 0.85em;'>
				<a href='#' class='video-poster-generate' data-url='$videoUrl' data-page-id='$pageId' data-filename='$basename'$auto style='color: #e83e8c;'>
					<i class='fa fa-magic'></i> Generate Thumbnail
				</a>
			</div>";
		} else {
			// Already created - show preview link
			// Using UIkit lightbox if available (AdminThemeUikit uses it)
			// data-uk-lightbox placed on wrapper, links inside
			$toolbar = "<div class='video-poster-actions' style='margin-top: 5px; font-size: 0.85em;'>
				<span class='video-poster-status' style='color: #28a745; margin-right: 10px;'>
					<i class='fa fa-check-circle'></i> Thumbnail exists
				</span>
				<span data-uk-lightbox style='margin-right: 10px;'>
					<a href='$coverUrl' data-caption='Thumbnail for $basename'>
						<i class='fa fa-eye'></i> Preview
					</a>
				</span>
				<a href='#' class='video-poster-generate' data-url='$videoUrl' data-page-id='$pageId' data-filename='$basename' title='Regenerate' style='color: #6c757d;'>
					<i class='fa fa-refresh'></i> Regenerate
				</a>
			</div>";
		}
		return $toolbar;
	}

	/**
	 * Hook to add generate thumbnail link to file items
	 */
	public function hookRenderItem(HookEvent $event)
	{
		$pagefile = $event->arguments(0);

		// Only relevant for videos
		$ext = strtolower($pagefile->ext);
		if (!in_array($ext, ['mp4', 'webm', 'ogg', 'mov']))
			return;

		// Check if cover already exists
		$coverUrl = $pagefile->videoCoverUrl();

		$out = $event->return;
		// Rendered in response to an admin upload: generate the poster right away
		$isUpload = $this->wire('input')->get('InputfieldFileAjax') && isset($_SERVER['HTTP_X_FILENAME']);
		$out .= $this->renderToolbar($pagefile->url, $coverUrl, $pagefile->basename, $pagefile->page->id, $isUpload);

		$event->return = $out;
	}

	/**
	 * Handle the upload request
	 */
	public function handleUpload()
	{
		$input = $this->wire('input');

		// Handle JSON input if needed, but standard POST is easier with FormData
		$pageId = (int) $input->post->page_id;
		$filename = $this->wire('sanitizer')->filename($input->post->filename);
		$imageData = $input->post->image; // Base64 string

		if (!$pageId || !$filename || !$imageData) {
			return ['success' => false, 'message' => 'Missing data'];
		}

		// Validate page access
		$p = $this->wire('pages')->get($pageId);
		if (!$p->id || !$p->editable()) {
			return ['success' => false, 'message' => 'Permission denied'];
		}

		if (!$this->isVideoFilename($filename) || !in_array($filename, $this->videoBasenames($p))) {
			return ['success' => false, 'message' => 'Video not found on page'];
		}

		// Decode image
		// Data URI format: data:image/jpeg;base64,......
		if (strpos($imageData, 'base64,') !== false) {
			$data = explode('base64,', $imageData);
			$imageData = $data[1];
		}

		$decoded = base64_decode($imageData);
		if (!$decoded)
			return ['success' => false, 'message' => 'Invalid image data'];

		// Determine storage path
		$basePath = $this->wire('config')->paths->root . ltrim($this->storagePath, '/');
		$savePath = $basePath . $pageId . '/';

		if (!is_dir($savePath)) {
			if (!wireMkdir($savePath, true)) {
				return ['success' => false, 'message' => 'Could not create directory'];
			}
		}

		// We use .webp for storage in handleUpload
		$posterName = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
		$fullPath = $savePath . $posterName;

		if (file_put_contents($fullPath, $decoded)) {
			$url = $this->wire('config')->urls->root . ltrim($this->storagePath, '/') . $pageId . '/' . $posterName;

			$videoUrl = $p->filesManager()->url() . $filename;

			$markup = $this->renderToolbar($videoUrl, $url, $filename, $pageId);

			return [
				'success' => true,
				'path' => $fullPath,
				'url' => $url,
				'toolbarMarkup' => $markup
			];
		} else {
			return ['success' => false, 'message' => 'Write failed'];
		}
	}

	public function hookVideoCoverUrl(HookEvent $event)
	{
		$pagefile = $event->object;
		$page = $pagefile->page;
		$filename = $pagefile->basename;
		$http = $event->arguments(0);

		// Check if it's a video
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, ['mp4', 'webm', 'ogg', 'mov'])) {
			$event->return = '';
			return;
		}

		$basePath = $this->storagePath; // e.g. /site/assets/video-covers/

		// Check for .webp first (new default), then .jpg (legacy)
		$posterNameWebp = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
		$posterNameJpg = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';

		$filePathWebp = $this->wire('config')->paths->root . ltrim($basePath, '/') . $page->id . '/' . $posterNameWebp;
		$filePathJpg = $this->wire('config')->paths->root . ltrim($basePath, '/') . $page->id . '/' . $posterNameJpg;

		$urlRoot = $http ? $this->wire('config')->urls->httpRoot : $this->wire('config')->urls->root;

		if (file_exists($filePathWebp)) {
			$event->return = $urlRoot . ltrim($basePath, '/') . $page->id . '/' . $posterNameWebp;
		} elseif (file_exists($filePathJpg)) {
			$event->return = $urlRoot . ltrim($basePath, '/') . $page->id . '/' . $posterNameJpg;
		} else {
			$event->return = '';
		}
	}

	protected function isVideoFilename($filename)
	{
		return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogg', 'mov']);
	}

	/**
	 * Video basenames in $page's file fields, including uploads not saved yet
	 */
	protected function videoBasenames(Page $page)
	{
		$basenames = [];
		foreach ($page->fieldgroup as $field) {
			if (!$field->type instanceof FieldtypeFile) continue;
			$files = $page->getUnformatted($field->name);
			if (!$files instanceof Pagefiles) continue;
			foreach ($files as $file) {
				if ($this->isVideoFilename($file->basename)) $basenames[] = $file->basename;
			}
		}
		return $basenames;
	}

	public function ___upgrade($fromVersion, $toVersion)
	{
		$this->relocateStrayPosters();
	}

	/**
	 * Before 1.0.1, posters generated on upload were saved under the edited page
	 * instead of the repeater item (or under the browser's filename instead of the
	 * stored one). Move each to where videoCoverUrl() looks when exactly one video matches.
	 */
	protected function relocateStrayPosters()
	{
		$basePath = $this->wire('config')->paths->root . ltrim($this->storagePath, '/');
		if (!is_dir($basePath)) return;

		$moved = 0;
		foreach (scandir($basePath) as $dir) {
			if (!ctype_digit($dir)) continue;
			$owner = $this->wire('pages')->get((int) $dir);
			if (!$owner->id) continue;

			$videos = $this->findVideoFiles($owner);
			foreach (scandir($basePath . $dir) as $poster) {
				$ext = strtolower(pathinfo($poster, PATHINFO_EXTENSION));
				if (!in_array($ext, ['webp', 'jpg'])) continue;
				$name = pathinfo($poster, PATHINFO_FILENAME);
				if (isset($videos[$dir][$name])) continue;

				$matches = [];
				foreach ($videos as $pageId => $names) {
					foreach ($names as $videoName) {
						if (strtolower($videoName) === strtolower($name)) $matches[] = [$pageId, $videoName];
					}
				}
				if (count($matches) !== 1) continue;

				list($pageId, $videoName) = $matches[0];
				$targetDir = $basePath . $pageId . '/';
				if (is_file($targetDir . "$videoName.webp") || is_file($targetDir . "$videoName.jpg")) continue;
				if (!is_dir($targetDir) && !wireMkdir($targetDir, true)) continue;
				if (rename($basePath . $dir . '/' . $poster, $targetDir . "$videoName.$ext")) $moved++;
			}
		}

		if ($moved) $this->message("InputfieldFileVideoPoster: moved $moved poster(s) to their video's page");
	}

	/**
	 * Video filenames (without extension) on $page and its repeater items, keyed by page ID
	 */
	protected function findVideoFiles(Page $page, $depth = 0)
	{
		$videos = [];
		foreach ($this->videoBasenames($page) as $basename) {
			$name = pathinfo($basename, PATHINFO_FILENAME);
			$videos[$page->id][$name] = $name;
		}
		if ($depth > 3) return $videos;

		foreach ($this->wire('pages')->find("name=for-page-{$page->id}, include=all") as $parent) {
			foreach ($parent->children('include=all') as $item) {
				$videos = $videos + $this->findVideoFiles($item, $depth + 1);
			}
		}
		return $videos;
	}

	public function install()
	{
		// Create the base directory
		$path = $this->wire('config')->paths->root . ltrim($this->storagePath, '/');
		if (!is_dir($path)) {
			wireMkdir($path, true);
		}
	}

	/**
	 * Module Configuration
	 */
	public static function getModuleConfigInputfields(array $data)
	{
		$modules = wire('modules');
		$inputfields = new InputfieldWrapper();

		$f = $modules->get('InputfieldText');
		$f->name = 'storagePath';
		$f->label = 'Storage Path';
		$f->description = 'Relative to site root. Example: /site/assets/video-covers/';
		$f->value = isset($data['storagePath']) ? $data['storagePath'] : '/site/assets/video-covers/';
		$inputfields->add($f);

		return $inputfields;
	}
}
