# Inputfield File Video Poster for ProcessWire

This module automatically generates poster images (thumbnails) for video files uploaded in the ProcessWire admin. It uses client-side HTML Canvas to generate the image from the video file and uploads it to the server.

## Features

-   **Automatic Generation**: Detects video uploads in `InputfieldFile` and generates a poster image.
-   **Client-Side Processing**: Uses the browser's native video capabilities to extract a frame.
-   **Configurable Storage**: Choose where to save the generated images.
-   **Easy Retrieval**: Adds a method to `Pagefile` objects to easily get the poster URL.

## Installation

### Via Composer

```bash
composer require elabx/inputfield-file-video-poster
```

### Manual Installation

1.  Download the module files.
2.  Place them in `/site/modules/InputfieldFileVideoPoster/`.
3.  Go to **Admin > Modules > Refresh**.
4.  Install **Inputfield File Video Poster**.

## Configuration

Go to **Admin > Modules > Configure > InputfieldFileVideoPoster**.

-   **Storage Path**: The directory where poster images will be saved, relative to the site root. Default is `/site/assets/video-covers/`.

## Usage

### Generating Posters

Simply upload a video file (mp4, webm, etc.) to any File or Image field in the ProcessWire admin. The module will automatically:
1.  Detect the video file.
2.  Generate a thumbnail from the 1-second mark (or 50% for short videos).
3.  Upload the thumbnail to the configured storage path.

### Displaying Posters

The module adds a `videoCoverUrl()` method to `Pagefile` objects.

```php
// In your template file
$video = $page->files->first(); // Assuming 'files' is your field name

if ($video) {
    $posterUrl = $video->videoCoverUrl();
    
    if ($posterUrl) {
        echo "<video controls poster='$posterUrl'>";
        echo "<source src='$video->url' type='video/mp4'>";
        echo "</video>";
    } else {
        // Fallback if no poster exists
        echo "<video controls src='$video->url'></video>";
    }
}
```

## Requirements

-   ProcessWire >= 3.0.173
-   Browser with support for HTML5 Video and Canvas.
