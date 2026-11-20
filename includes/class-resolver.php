<?php
/**
 * Decides whether an image URL points at a file that is actually there.
 *
 * @package BrokenImageCleaner
 */

namespace BrokenImageCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps an image URL to a path in the uploads folder and checks the file.
 *
 * Only files inside the uploads folder are checked, and only on disk. There are
 * no network requests, so nothing here can be misled by a timeout, a rate limit
 * or hotlink protection — the two answers this class gives, "the file is there"
 * and "the file is not there", are both certain.
 */
class Resolver {

	/**
	 * The file exists and is usable.
	 *
	 * Returned by check() as part of its contract. Current callers only test
	 * whether the status is broken, but all three statuses are legitimate
	 * return values and future callers may distinguish between them.
	 */
	const STATUS_OK = 'ok';

	/**
	 * The file is missing or empty.
	 */
	const STATUS_BROKEN = 'broken';

	/**
	 * Out of scope for this version, so no opinion is offered.
	 *
	 * Returned by check() as part of its contract. Current callers only test
	 * whether the status is broken, but all three statuses are legitimate
	 * return values and future callers may distinguish between them.
	 */
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Uploads directory base path.
	 *
	 * @var string
	 */
	private $basedir = '';

	/**
	 * Uploads directory base URL, without a scheme.
	 *
	 * @var string
	 */
	private $baseurl = '';

	/**
	 * Real, symlink-resolved uploads path, used to contain path traversal.
	 *
	 * @var string
	 */
	private $real_basedir = '';

	/**
	 * Prepare the uploads paths once per instance.
	 */
	public function __construct() {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['error'] ) ) {
			$this->basedir = untrailingslashit( $uploads['basedir'] );
			$this->baseurl = untrailingslashit( $this->strip_scheme( $uploads['baseurl'] ) );

			$real = realpath( $this->basedir );

			if ( false !== $real ) {
				$this->real_basedir = untrailingslashit( $real );
			}
		}
	}

	/**
	 * Check a single image URL.
	 *
	 * @param string $url Image URL as written in the content.
	 * @return array {
	 *     @type string $status One of the STATUS_* constants.
	 *     @type string $reason A Store::REASON_* constant when broken.
	 *     @type string $path   Resolved filesystem path, when one was found.
	 * }
	 */
	public function check( $url ) {
		$path = $this->to_path( $url );

		if ( false === $path ) {
			return $this->result( self::STATUS_SKIPPED );
		}

		if ( ! file_exists( $path ) ) {
			$reason = $this->is_missing_generated_size( $path )
				? Store::REASON_SIZE_MISSING
				: Store::REASON_FILE_MISSING;

			return $this->result( self::STATUS_BROKEN, $reason, $path );
		}

		if ( 0 === (int) filesize( $path ) ) {
			return $this->result( self::STATUS_BROKEN, Store::REASON_ZERO_BYTE, $path );
		}

		return $this->result( self::STATUS_OK, '', $path );
	}

	/**
	 * Map an image URL to a path inside the uploads folder.
	 *
	 * @param string $url Image URL.
	 * @return string|false Path, or false when the URL is out of scope.
	 */
	public function to_path( $url ) {
		if ( '' === $this->basedir || '' === $this->real_basedir ) {
			return false;
		}

		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return false;
		}

		// Query strings and fragments are cache-busters, not part of the filename.
		$url = preg_replace( '/[?#].*$/', '', $url );

		$relative = $this->relative_upload_path( $url );

		if ( false === $relative ) {
			return false;
		}

		$candidate = $this->basedir . '/' . ltrim( $relative, '/' );

		return $this->contain( $candidate );
	}

	/**
	 * Work out where a URL sits relative to the uploads folder.
	 *
	 * @param string $url Image URL, already cleaned of query and fragment.
	 * @return string|false Relative path, or false when the URL is not a local upload.
	 */
	private function relative_upload_path( $url ) {
		$comparable = $this->strip_scheme( $url );

		// Absolute or protocol-relative URL: it must sit under the uploads base URL.
		if ( 0 === strpos( $comparable, '//' ) ) {
			if ( 0 !== strpos( $comparable, $this->baseurl . '/' ) ) {
				return false;
			}

			return substr( $comparable, strlen( $this->baseurl ) );
		}

		// Root-relative URL: compare against the uploads path on this host.
		if ( 0 === strpos( $url, '/' ) ) {
			$basepath = wp_parse_url( 'https:' . $this->baseurl, PHP_URL_PATH );

			if ( ! is_string( $basepath ) || 0 !== strpos( $url, $basepath . '/' ) ) {
				return false;
			}

			return substr( $url, strlen( $basepath ) );
		}

		// Anything else (a bare relative path, a mailto:, a malformed URL) is out of scope.
		return false;
	}

	/**
	 * Reject any path that escapes the uploads folder.
	 *
	 * The parent directory is resolved rather than the file itself, because the
	 * file being absent is the normal case here and realpath() would fail on it.
	 *
	 * @param string $candidate Candidate filesystem path.
	 * @return string|false
	 */
	private function contain( $candidate ) {
		$directory = realpath( dirname( $candidate ) );

		if ( false === $directory ) {
			// The directory itself is gone, so the file certainly is too. Fall back
			// to a textual check so the finding is still reported.
			$normalised = $this->normalise( $candidate );

			return 0 === strpos( $normalised, $this->real_basedir . '/' ) ? $normalised : false;
		}

		$directory = untrailingslashit( $directory );

		if ( $directory !== $this->real_basedir && 0 !== strpos( $directory, $this->real_basedir . '/' ) ) {
			return false;
		}

		return $directory . '/' . basename( $candidate );
	}

	/**
	 * Flatten `.` and `..` segments out of a path without touching the filesystem.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	private function normalise( $path ) {
		$segments = array();

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return '/' . implode( '/', $segments );
	}

	/**
	 * Whether a missing file looks like a generated size whose original survives.
	 *
	 * `photo-150x150.jpg` missing while `photo.jpg` is present means thumbnails
	 * need regenerating. Deleting the image would be the wrong repair, so this is
	 * reported separately and kept out of the default selection.
	 *
	 * @param string $path Missing file path.
	 * @return bool
	 */
	private function is_missing_generated_size( $path ) {
		$matches = array();

		if ( ! preg_match( '/^(.+)-\d+x\d+(\.[A-Za-z0-9]+)$/', $path, $matches ) ) {
			return false;
		}

		$original = $matches[1] . $matches[2];

		return file_exists( $original ) && filesize( $original ) > 0;
	}

	/**
	 * Drop the scheme from a URL so http and https compare equal.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function strip_scheme( $url ) {
		return preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', (string) $url );
	}

	/**
	 * Shape a check result.
	 *
	 * @param string $status Status constant.
	 * @param string $reason Reason constant.
	 * @param string $path   Resolved path.
	 * @return array
	 */
	private function result( $status, $reason = '', $path = '' ) {
		return array(
			'status' => $status,
			'reason' => $reason,
			'path'   => $path,
		);
	}
}
