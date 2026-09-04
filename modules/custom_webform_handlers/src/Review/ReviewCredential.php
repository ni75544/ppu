<?php

namespace Drupal\custom_webform_handlers\Review;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\PrivateKey;
use Drupal\Core\Site\Settings;

/**
 * Issues and verifies the code a customer tracks their own request with.
 *
 * The credential is the whole of the tracker's access control. There is no account
 * to log into — a courier customer is anonymous by the time they submit — so the
 * page has to be reachable by somebody holding one secret and by nobody else. That
 * puts two requirements on the code, and this class exists to hold both in one
 * place rather than have them restated at every call site.
 *
 * First, it must be unguessable. It is 24 bytes from the system's cryptographic
 * source, rendered URL-safe: 192 bits, which is not enumerable and not derivable
 * from a submission identifier, a timestamp, or a customer's own details. Nothing
 * about the request is encoded in it, so possession of one code says nothing about
 * any other.
 *
 * Second, the stored form must be useless on its own. What is written to the row is
 * an HMAC, keyed by the site's private key and hash salt — both of which live
 * outside the database, in settings and in the key/value store the database dump
 * does not carry. So a leaked copy of the review table yields no working tracking
 * link: the hashes in it cannot be reversed, and they cannot be replayed as codes
 * because the tracker hashes what it is given before matching. This is the reason
 * item 3 of the specification says to store no raw public token, and this is where
 * that is made true.
 *
 * The comparison is length-safe by construction: both sides are fixed-length
 * base64 HMACs of the same width, and the match happens as an equality condition
 * inside one indexed SQL lookup rather than as a loop over candidate rows in PHP.
 */
final class ReviewCredential {

  /**
   * Random bytes per code. 24 gives 192 bits and a 32-character URL-safe string.
   */
  const CODE_BYTES = 24;

  /**
   * Namespace mixed into the key, so a review code is not a token for anything else.
   *
   * Two subsystems keyed by the same private key would otherwise produce the same
   * digest for the same input, and a value issued for one purpose would verify for
   * the other. The namespace is what keeps a tracking code from ever being accepted
   * as some future credential of the same shape.
   */
  const KEY_NAMESPACE = 'custom_webform_handlers.review_tracker';

  public function __construct(
    private readonly PrivateKey $privateKey,
  ) {}

  /**
   * Mints a fresh code. The only place a raw credential comes into existence.
   *
   * The caller shows the result to the customer it belongs to and then lets it go.
   * Nothing in this module writes the return value to the database, to a log, to a
   * Webform element, or to a staff-visible page.
   */
  public function issue(): string {
    return Crypt::randomBytesBase64(self::CODE_BYTES);
  }

  /**
   * Reduces a code to the value stored against the request.
   *
   * An empty string in gives an empty string out, rather than the hash of nothing.
   * That matters: the hash of the empty string is a stable value that would match
   * every row written from an empty code, so returning it would turn a blank
   * tracking form into a lookup that could succeed. An empty result is refused by
   * the store before it becomes a query.
   */
  public function hash(string $code): string {
    $code = self::normalize($code);
    if ($code === '') {
      return '';
    }

    return Crypt::hmacBase64($code, self::KEY_NAMESPACE . $this->privateKey->get() . Settings::getHashSalt());
  }

  /**
   * Bounds what will be treated as a code at all.
   *
   * URL-safe base64 characters only, and no longer than a code this class issues.
   * A value that is not shaped like a code is reduced to the empty string here, so
   * a lookup for it never reaches the database and cannot be a probe: every
   * malformed input costs the same nothing as every wrong-but-well-formed one.
   *
   * Case is preserved, because base64 is case-sensitive and folding it would throw
   * away a third of the entropy.
   */
  public static function normalize(string $code): string {
    $code = trim($code);
    if ($code === '' || strlen($code) > 64) {
      return '';
    }

    return preg_match('/^[A-Za-z0-9_\-]+$/', $code) === 1 ? $code : '';
  }

}
