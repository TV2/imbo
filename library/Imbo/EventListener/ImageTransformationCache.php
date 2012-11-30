<?php
/**
 * Imbo
 *
 * Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package EventListener
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/imbo/imbo
 */

namespace Imbo\EventListener;

use Imbo\EventManager\EventInterface,
    Imbo\EventManager\EventManager,
    Imbo\Http\ContentNegotiation,
    Imbo\Image\Image,
    RecursiveDirectoryIterator,
    RecursiveIteratorIterator;

/**
 * Image transformation cache
 *
 * Event listener that stores transformed images to disk. By using this listener Imbo will only
 * have to generate each transformation once. Requests for original images (no t[] in the URI)
 * will not be cached. The listener will also delete images from the cache when they are deleted
 * from Imbo.
 *
 * @package EventListener
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/imbo/imbo
 */
class ImageTransformationCache implements ListenerInterface {
    /**
     * Root path where the temp. images can be stored
     *
     * @var string
     */
    private $path;

    /**
     * Content negotiation instance
     *
     * @var ContentNegotiation
     */
    private $contentNegotiation;

    /**
     * Class constructor
     *
     * @param string $path Path to store the temp. images
     * @param ContentNegotiation $contentNegotiation Content negotiation instance
     */
    public function __construct($path, ContentNegotiation $contentNegotiation = null) {
        $this->path = rtrim($path, '/');

        if ($contentNegotiation === null) {
            $contentNegotiation = new ContentNegotiation();
        }

        $this->contentNegotiation = $contentNegotiation;
    }

    /**
     * {@inheritdoc}
     */
    public function attach(EventManager $manager) {
        $manager
            // Look for images in the cache before transformations occur
            ->attach('image.transform', array($this, 'loadFromCache'), 20)

            // Store images in the cache after transformations has occured
            ->attach('image.transform', array($this, 'storeInCache'), -20)

            // Remove from the cache when an image is deleted from Imbo
            ->attach('image.delete', array($this, 'deleteFromCache'), 10);
    }

    /**
     * Load transformed images from the cache
     *
     * @param EventInterface $event The current event
     */
    public function loadFromCache(EventInterface $event) {
        $request = $event->getRequest();

        if (!$request->hasTransformations()) {
            // No transformations, nothing to cache
            return;
        }

        $response = $event->getResponse();
        $image = $response->getImage();
        $eventName = $event->getName();
        $publicKey = $request->getPublicKey();
        $imageIdentifier = $request->getImageIdentifier();
        $extension = $request->getExtension();
        $url = $request->getUrl();

        if ($extension) {
            // The user has requested a specific type (convert transformation). Use that mime type
            $tables = Image::$mimeTypes;
            $types = array_flip($tables); // ╯°□°）╯︵ ┻━┻
            $mimeType = $types[$extension];
        } else {
            $mimeType = $this->contentNegotiation->bestMatch(
                array_keys(Image::$mimeTypes),
                $request->getAcceptableContentTypes()
            );
        }

        if (!$mimeType) {
            // The client does not seem to accept any of our mime types. What a douche!
            return;
        }

        // Generate cache key and fetch the full path of the cached response
        $hash = $this->getCacheKey($url, $mimeType);
        $fullPath = $this->getCacheFilePath($request->getImageIdentifier(), $hash);

        if (is_file($fullPath)) {
            $image->setBlob(file_get_contents($fullPath))
                  ->setMimeType($mimeType);

            $response->getHeaders()->set('X-Imbo-TransformationCache', 'Hit');
            $event->stopPropagation(true);

            return;
        }

        $response->getHeaders()->set('X-Imbo-TransformationCache', 'Miss');
    }

    /**
     * Store transformed images in the cache
     *
     * @param EventInterface $event The current event
     */
    public function storeInCache(EventInterface $event) {
        $request = $event->getRequest();

        if (!$request->hasTransformations()) {
            return;
        }

        $response = $event->getResponse();

        if ($response->getStatusCode() !== 200) {
            // We only want to put 200 OK responses in the cache
            return;
        }

        $image = $response->getImage();

        $hash = $this->getCacheKey($request->getUrl(), $image->getMimeType());
        $fullPath = $this->getCacheFilePath($request->getImageIdentifier(), $hash);

        $dir = dirname($fullPath);

        if (is_dir($dir) || mkdir($dir, 0775, true)) {
            if (file_put_contents($fullPath . '.tmp', $image->getBlob())) {
                rename($fullPath . '.tmp', $fullPath);
            }
        }
    }

    /**
     * Delete cached images from the cache
     *
     * @param EventInterface $event The current event
     */
    public function deleteFromCache(EventInterface $event) {
        $cacheDir = $this->getCacheDir($event->getRequest()->getImageIdentifier());

        if (is_dir($cacheDir)) {
            $this->rmdir($cacheDir);
        }
    }

    /**
     * Get the path to the current image cache dir
     *
     * @param string $imageIdentifier The image identifier
     * @return string Returns the absolute path to the image cache dir
     */
    private function getCacheDir($imageIdentifier) {
        return sprintf('%s/%s/%s/%s/%s', $this->path, $imageIdentifier[0], $imageIdentifier[1], $imageIdentifier[2], $imageIdentifier);
    }

    /**
     * Get the absolute path to cache file
     *
     * @param string $imageIdentifier The image identifier
     * @param string $hash The hash used as cache key
     * @return string Returns the absolute path to the cache file
     */
    private function getCacheFilePath($imageIdentifier, $hash) {
        return sprintf('%s/%s/%s/%s/%s', $this->getCacheDir($imageIdentifier), $hash[0], $hash[1], $hash[2], $hash);
    }

    /**
     * Generate a cache key
     *
     * @param string $url The request URL
     * @param string $mime The mime type
     * @return string Returns a string that can be used as a cache key for the current image
     */
    private function getCacheKey($url, $mime) {
        return hash('sha256', $url . '|' . $mime);
    }

    /**
     * Completely remove a directory (with contents)
     *
     * @param string $dir Name of a directory
     */
    private function rmdir($dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $name = $file->getPathname();

            if (substr($name, -1) === '.') {
                continue;
            }

            if ($file->isDir()) {
                // Remove dir
                rmdir($name);
            } else {
                // Remove file
                unlink($name);
            }
        }

        // Remove the directory itself
        rmdir($dir);
    }
}
