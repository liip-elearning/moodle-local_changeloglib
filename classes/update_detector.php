<?php
// This file is part of ChangelogLib plugin for Moodle - http://moodle.org/
//
// ChangelogLib is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// ChangelogLib is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with ChangelogLib.  If not, see <http://www.gnu.org/licenses/>.

/**
 * ChangelogLib.
 * A library to support other plugins which have to detect changes in files.
 * This plugin does not do anything of its own. It is only useful as a
 * dependency for other plugins.
 *
 * @package   local_changeloglib
 * @author    Hendrik Wuerz <hendrikmartin.wuerz@stud.tu-darmstadt.de>
 * @copyright (c) 2017 Hendrik Wuerz
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__) . '/../definitions.php');
require_once(dirname(__FILE__) . '/backup_lib.php');

/**
 * Checks whether one file is an update of another.
 */
class local_changeloglib_update_detector {

    /**
     * The file instance for the new file whose predecessor should be found.
     * @var stored_file $new_file
     */
    private $new_file;

    /**
     * The data with additional information of the current file whose predecessor should be found.
     * Use this to pass the course module id for resources. This will be checked for definite predecessors.
     * @var array
     */
    private $new_data;

    /**
     * The moodle context in which the new file and the predecessor are stored.
     * @var int
     */
    private $context;

    /**
     * The scope within the context which must match between the new file and the predecessor.
     * This might be the course section for resources.
     * @var int
     */
    private $scope;

    /**
     * All other relevant files which are not in the backup table.
     * This might be used for deletion in progress files.
     * @var stored_file[]
     */
    private $further_candidates;

    /**
     * @var array All records in the backup table which are relevant as a predecessor.
     */
    private $candidates;

    /**
     * Whether this detector should ensure that the MIME type of the found predecessor matches
     * the MIME type of the new file.
     * If this value is true, all candidates with not matching type will be skipped.
     * If this value is false, the MIME Type will be handled as a similarity property.
     * @var bool
     */
    private $ensure_mime_type = true;

    /**
     * local_changeloglib_update_detector constructor.
     * @param stored_file $new_file The new file whose predecessor should be found.
     * @param array $new_data The data array of the new file with constraints for a definite predecessor.
     * @param int $context The context of the file and its predecessor. (Resources: The course context)
     * @param int $scope The scope of the file and its predecessor within the context. (In courses: the section)
     * @param stored_file[] $further_candidates All other files which should be checked as predecessor.
     *                                          Use this for deletion in progress.
     */
    public function __construct(stored_file $new_file, array $new_data, $context, $scope, array $further_candidates) {
        $this->new_file = $new_file;
        $this->new_data = $new_data;
        $this->context = $context;
        $this->scope = $scope;
        $this->further_candidates = $further_candidates;

        $this->candidates = $this->get_candidates();
    }

    /**
     * Get the new file.
     * @return stored_file The new file.
     */
    public function get_new_file() {
        return $this->new_file;
    }

    /**
     * Whether this detector should ensure that the MIME type of the found predecessor matches
     * the MIME type of the new file.
     * If this value is true, all candidates with not matching type will be skipped.
     * If this value is false, the MIME Type will be handled as a similarity property.
     * @param bool $ensure_mime_type Whether the MIME type of the candidates must match or not.
     */
    public function set_ensure_mime_type($ensure_mime_type) {
        $this->ensure_mime_type = $ensure_mime_type;
    }

    /**
     * Get all backups stored with the same context and scope.
     * @return array The available records.
     */
    private function get_candidates() {
        global $DB;

        return $DB->get_records(local_changeloglib_backup_lib::BACKUP_TABLE, array(
            'context' => $this->context,
            'scope' => $this->scope
        ), 'timestamp DESC');
    }

    /**
     * Check whether the new file is an update of an older version.
     * @return bool|stored_file False if this is not an update of an earlier file. The previous version of this file if found.
     */
    public function is_update() {

        $candidate = $this->get_best_candidate();

        // No candidate was found
        if ($candidate == null) {
            return false;
        }

        // Threshold: If the candidate similarity is lower this value is is not a predecessor
        if ($candidate->similarity < 0.5) {
            return false;
        }

        // Check whether the files are identically.
        // The detector might be called if a module becomes updated. In this case it is possible, that only meta information
        // were changed and the file itself is the same as before. In this case it must not be recognized as an update
        // of itself.
        if ($this->new_file->get_contenthash() == $candidate->file->get_contenthash()) {
            return false;
        }

        return $candidate->file;
    }

    /**
     * Get the best candidate for an update. If a fitting definite predecessor was found, it will be returned. Otherwise
     * the best predecessor based on the backups and the further_candidates will be found.
     * @return null|stdClass
     * Null is returned if no candidate fits.
     * The stdClass contains the key of the best candidate, the calculated similarity and the stored_file of the best candidate
     */
    private function get_best_candidate() {

        // Check whether additional data are passed to the new file.
        // Only if some are present, a search for a definite predecessor makes sense.
        if (count($this->new_data) > 0) {
            // Check whether there is a definite predecessor. (= a backup of exactly this course module)
            // Use it, if it is not completely unfitting.
            $definite_predecessor = $this->get_definite_predecessor();
            if ($definite_predecessor != null && $definite_predecessor->similarity > 0.2) {
                return $definite_predecessor;
            }
        }

        // Get the best candidate from both origins: The already deleted but backed files and the further_candidates.
        return $this->get_best_meta_candidate();
    }

    /**
     * Check whether there is a previous version of exactly this element is stored.
     * This situation happens if the user updates a file via the 'edit settings' dialog.
     * In this case the ID does not change and `data` will map.
     * Because Moodle will increase the IDs for each new file, an other upload can not be detected
     * as a definite predecessor falsely. (Only if `data` was set to a non-ID field)
     * @return null|stdClass Null if no definite predecessor could be found. StdClass width similarity
     * and file a definite predecessor was found. Hint: Similarity is always 1
     */
    private function get_definite_predecessor() {

        // Array of all found predecessors.
        // A candidate is only a predecessor if the stored data matches the values from the current file.
        $definite_predecessors = array();

        // Iterate candidates to check if it is a predecessor.
        foreach ($this->candidates as $candidate) {
            $data = json_decode($candidate->data, true);
            $is_equal = true;
            foreach ($this->new_data as $key => $value) { // Check each data attribute of the current file
                if ($data[$key] != $value) {
                    $is_equal = false;
                    break;
                }
            }
            if ($is_equal) { // The candidate is a valid predecessor
                $file = local_changeloglib_backup_lib::get_backup_file($candidate);
                $definite_predecessors[] = $file;
            }
        }

        return $this->check_candidates($definite_predecessors);
    }

    /**
     * Get the best candidate based on the meta information.
     * Candidates are taken from the backups and from `further_candidates`
     * @return null|stdClass
     * Null is returned if no candidate fits.
     * The stdClass contains the calculated similarity and the stored_file of the best candidate
     */
    private function get_best_meta_candidate() {

        // Get the file instances for pending candidates
        /** @var stored_file[] $candidate_stored_files */
        $candidate_stored_files = array_map(function ($candidate) {
            return local_changeloglib_backup_lib::get_backup_file($candidate);
        }, $this->candidates);
        $candidate_files = array_merge($candidate_stored_files, $this->further_candidates);

        return $this->check_candidates($candidate_files);
    }

    /**
     * Checks all passed files. Returns the best ob them with the calculated similarity.
     * @param stored_file[] $candidate_files
     * @return null|stdClass
     * Null is returned if no candidate fits.
     * The stdClass contains the calculated similarity and the `stored_file` of the best candidate
     */
    private function check_candidates($candidate_files) {

        // Store the data of the best candidate
        $best_candidate = -1;
        $best_similarity = 0;

        // Check each candidate whether it is the best
        foreach ($candidate_files as $key => $candidate_file) {

            // The types of the files must match
            $fitting_mime_type = $this->new_file->get_mimetype() == $candidate_file->get_mimetype();

            // The MIME types do not match and this detector should ensure, that they do.
            if ($this->ensure_mime_type && !$fitting_mime_type) {
                continue;
            }

            // The similarity in the range [0, 1]
            $similarity = self::calculate_meta_similarity($this->new_file, $candidate_file);

            // Check for soft handling of MIME-Types
            if (!$this->ensure_mime_type) { // This detector should not ensure the MIMe-Types...
                if ($fitting_mime_type) { // ... but they fit --> Increase the similarity
                    $similarity += 1;
                }
                // The similarity should be in the range [0, 1] again. If the detector does not ensure MIME Types
                // and the types do not match, the similarity will decrease.
                $similarity /= 2;
            }

            if ($similarity > $best_similarity) { // This candidate is the best until now
                $best_candidate = $key;
                $best_similarity = $similarity;
            }
        }

        // No candidate fits
        if ($best_candidate < 0) {
            return null;
        }

        // Build a response object based on the calculated similarity
        $response = new stdClass();
        $response->similarity = $best_similarity;
        $response->file = $candidate_files[$best_candidate];
        return $response;
    }

    /**
     * Calculates the similarity of the passed files based on meta information.
     * This means, the content will not become analysed.
     * @param stored_file $original The original file which is now uploaded
     * @param stored_file $candidate An candidate which might be a predecessor of the file.
     * @return float The similarity of the two files based on meta information. Value in range [0,1]
     */
    private static function calculate_meta_similarity(stored_file $original, stored_file $candidate) {

        $key_weight = 0;
        $key_similarity = 1;
        $factors = array();

        // How similar are the file names
        $filename = self::levenshtein_realtive($original->get_filename(), $candidate->get_filename());
        $factors[] = array($key_weight => 1, $key_similarity => $filename);

        // How similar is the file size
        $filesize = self::number_similarity_realtive($original->get_filesize(), $candidate->get_filesize());
        $factors[] = array($key_weight => 1, $key_similarity => $filesize);

        // How many minutes ago the candidate was deleted
        // Until one minute (= 60 sec) the similarity will not decrease
        $deletion_time = 1 / (1 + 0.01 * ( time() - $candidate->get_timemodified()));
        $factors[] = array($key_weight => 0.5, $key_similarity => $deletion_time);

        // Sum up all factors with their weights
        $weight_sum = 0;
        $similarity_sum = 0;
        foreach ($factors as $factor) {
            $weight_sum += $factor[$key_weight];
            $similarity_sum += $factor[$key_similarity];
        }
        return $similarity_sum / $weight_sum;
    }

    /**
     * Wrapper around levenshtein which calculates the operations relative to the string length
     * @see levenshtein
     * @param string $str1 The first string
     * @param string $str2 The second string
     * @return float The operations relative to the string length.
     */
    private static function levenshtein_realtive($str1, $str2) {
        return 1 - levenshtein($str1, $str2) / max(strlen($str1), strlen($str2));
    }

    /**
     * Calculates the similarits between the two numbers based on the relative difference between them
     * @param int $val1 The first number
     * @param int $val2 The second number
     * @return float The similarity of the passed numbers
     */
    private static function number_similarity_realtive($val1, $val2) {
        return 1 - abs($val1 - $val2) / max($val1, $val2);
    }
}