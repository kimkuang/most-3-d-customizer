<?php

namespace Libre3d\Render3d\Convert;

use Libre3d\Render3d\Render3d;

class StlPov extends Convert {

	/**
	 * Min values for x, y, and z
	 * 
	 * @var array
	 */
	protected $min = [];

	/**
	 * Max values for x, y, and z
	 * 
	 * @var array
	 */
	protected $max = [];

	/**
	 * Name used for the model in the mesh
	 * 
	 * @var string
	 */
	protected $modelName;

	public function convert() {
		$options = $this->Render3d->options();
		$bufferSize = 8000;
		if (isset($options['fwrite_buffer_size'])) {
			$bufferSize = (int)$options['fwrite_buffer_size'];
		}

		if ($this->Render3d->fileType() !== 'stl') {
			throw new \Exception('Wrong file type, cannot convert using this converter.');
		}

		$handle = fopen($this->Render3d->filename(), 'rb');
		if (!$handle) {
			throw new \Exception('Could not open STL file, convert failed.');
		}

		$parse = $this->parseBinary($handle, $bufferSize);
		if (!$parse) {
			rewind($handle);
			$parse = $this->parseTxt($handle, $bufferSize);
		}
		fclose($handle);

		if (!$parse || empty($this->modelName)) {
			throw new \Exception('Not a valid STL file.');
		}
		
		$diff['x'] = abs($this->max['x'] - $this->min['x']);
		$diff['y'] = abs($this->max['y'] - $this->min['y']);
		$diff['z'] = abs($this->max['z'] - $this->min['z']);
		
		//generate contents
		/**
		 * The template vars used by our layout template
		 * 
		 * @var array
		 */
		$tplVars = [];

		//insert the include file...
		$tplVars['includeFile'] = $this->Render3d->workingDir() . $this->Render3d->file() . '.pov-inc';
		
		$tplVars['modelname'] = $this->modelName;

		$tplVars['sceneDir'] = $this->Render3d->sceneDir();
		
		$tplVars['x'] = $diff['x'];
		$tplVars['y'] = $diff['y'];
		$tplVars['z'] = $diff['z'];
		
		//figure out what to use for the Z multipliers...
		
		//first one is for how far up (on z axis) to stick the camera...
		//default is a little above the top of the item...
		$mult = '1.2';
		
		$slopeThreshold = .33;
		
		//Figure out the "run" for the slope...  it's basically a triangle...
		$x = $diff['x']*2;
		$y = $diff['y']*2;
		$z = $diff['z']*$mult;
		// use pythagorean theorem
		// x^2 + y^2 = h^2 ... (x^2 + y^2)^0.5 = h
		$h = sqrt($x*$x + $y*$y);
		
		// now use h (hypotenuse) as the run.. and z as the rise...  See if the slope is less than our threshhold
		if (($diff['z']*$mult)/$h < $slopeThreshold) {
			// slope is not acceptable!  Figure out what to use a roughly 40% slope or so...
				
			// (z*mult) / h = .4 (z is "original z" pre-multiplier...) and solve for mult:
			$mult = ($h * 0.4) / $diff['z'];
		}
		$tplVars['zMult'] = round($mult, 2);
		
		// figure out things for the grid...
		
		// This is figuring out how large to make the grid, we only want to take up a part of the floor
		// (the part that the object takes up)
		$axesSize = 100;
		$axesMult = ceil( max($diff['x'],$diff['y']) / ($axesSize));
		
		$tplVars['axesSize'] = $axesSize * $axesMult;
		
		$povContents = $this->generatePov($tplVars);
		if (empty($povContents)) {
			// Error generating contents
			throw new \Exception('Problem generating contents.');
		}

		//attempt to write it to file
		$file = $this->Render3d->file() . '.pov';
		if (!file_put_contents($file, $povContents)) {
			throw new \Exception('Problem writing to file.');
		}
		if (!strlen(file_get_contents($file))) {
			throw new \Exception('File contents empty!  Pov file failed.');
		}
		$this->Render3d->fileType('pov');
	}

	/**
	 * Clean the model name as set in STL file.  Removes white space and other characters.
	 * 
	 * @param string $name
	 * @return string
	 */
	protected function cleanModelName ($name) {
		$name = str_replace('solid ', '', $name);
		$name = trim($name, "\x00 \t\n\r");
		return trim(preg_replace('/[^_a-zA-Z0-9]+/','_',$name), '_');
	}

	/**
	 * Parse a binary STL file.  If it appears to be text STL, or there are problems processing, will return false.
	 * 
	 * @param resource $handle The file handle resource for the STL file
	 * @param int $bufferSize
	 * @return bool True if able to parse the STL file, false otherwise
	 */
	protected function parseBinary($handle, $bufferSize) {
		$line = fread($handle, 84);
		if (strpos($line, 'facet normal') !== false) {
			// not binary
			return false;
		}
		// Note: only take the name data, throw away the other data in this block as it is not reliable
		$parts = unpack("a80name/I", $line);
		$name = 'm_'.$this->cleanModelName($parts['name']);
		unset($parts);

		$points = [];
		$fn = $this->Render3d->file() . '.pov-inc';
		$incFile = fopen($fn, 'w');

		if (!$incFile) {
			// Some error creating it
			return false;
		}
		$this->fwriteBuffer($incFile, "// Generated by Render-3d stl to pov converter on ".date(DATE_RFC2822)."\n", $fn, $bufferSize);
		$this->fwriteBuffer($incFile, "# declare $name = mesh {\n", $fn, $bufferSize);
		// parse the coords!
		while (!feof($handle)) {
			$line = fread($handle, 50);
			if (strlen($line) !== 50) {
				// probably end of thing
				break;
			}
			$parts = unpack("x12/f9/x2", $line);
			
			// map: 2, 1, 3
			$p = [
				[$parts[2], $parts[1], $parts[3]],
				[$parts[5], $parts[4], $parts[6]],
				[$parts[8], $parts[7], $parts[9]]
			];
			$this->writeTriangle($p, $incFile, $fn, $bufferSize);
		}
		// Write any remaining buffer
		$this->fwriteBuffer($incFile, "}", $fn, 0);
		fclose($incFile);
		$this->modelName = $name;

		return true;
	}

	/**
	 * Parse a text STL file.  If there are problems processing, will return false.
	 * 
	 * @param resource $handle The file handle resource for the STL file
	 * @param int $bufferSize
	 * @return bool True if able to parse the STL file, false otherwise
	 */
	protected function parseTxt($handle, $bufferSize) {
		$firstLine = trim(fgets($handle));
		if (strpos($firstLine, 'solid') !== 0 || strpos(fgets($handle), 'facet normal') === false) {
			// does not look right
			return false;
		}
		$lParts = explode(' ', $firstLine);
		$name = 'm_'.$this->cleanModelName($lParts[1]);
		unset($parts);

		$points = [];
		$fn = $this->Render3d->file() . '.pov-inc';
		$incFile = fopen($fn, 'w');

		if (!$incFile) {
			// Some error creating it
			return false;
		}
		$this->fwriteBuffer($incFile, "// Generated by Render-3d stl to pov converter on ".date(DATE_RFC2822)."\n", $fn, $bufferSize);
		$this->fwriteBuffer($incFile, "# declare $name = mesh {\n", $fn, $bufferSize);
		// parse the coords!
		while (!feof($handle)) {
			$l1 = $l2 = $l3 = null;
			while (!feof($handle)) {
				// Go until we get to first "vertex"
				$l1 = explode(' ', trim(fgets($handle)));
				if (empty($l1) || trim($l1[0]) === 'vertex') {
					// this is the line
					break;
				}
			}
			if (empty($l1) || trim($l1[0]) !== 'vertex') {
				// may be at end of file, or corrupted file
				break;
			}
			$l2 = explode(' ', trim(fgets($handle)));
			$l3 = explode(' ', trim(fgets($handle)));
			if (empty($l2) || empty($l3) || trim($l2[0]) !== 'vertex' || trim($l3[0]) !== 'vertex') {
				// something wrong, maybe bad file or file ended early
				break;
			}
			
			// map: 2, 1, 3
			$p = [
				[$l1[2], $l1[1], $l1[3]],
				[$l2[2], $l2[1], $l2[3]],
				[$l3[2], $l3[1], $l3[3]]
			];
			$this->writeTriangle($p, $incFile, $fn, $bufferSize);
		}
		// Write any remaining buffer
		$this->fwriteBuffer($incFile, "}", $fn, 0);
		fclose($incFile);
		$this->modelName = $name;

		return true;
	}

	/**
	 * Generate the triangle line for POV mesh based on 2 dimension array, and parses the cords
	 * 
	 * @param array $t
	 * @return string
	 */
	protected function writeTriangle ($t, $handle, $fn, $bufferSize) {
		$this->fwriteBuffer($handle, "  triangle{\n    <{$t[0][0]}, {$t[0][1]}, {$t[0][2]}>,\n    <{$t[1][0]}, {$t[1][1]}, {$t[1][2]}>,\n    <{$t[2][0]}, {$t[2][1]}, {$t[2][2]}>\n  }\n", $fn, $bufferSize);
		$this->parseCords($t);
	}

	/**
	 * Generate the POV file contents
	 * 
	 * @param array $tplVars
	 * @return string The POV contents to use
	 */
	protected function generatePov($tplVars) {
		$options = $this->Render3d->options();

		$defaultLayoutFile = $this->Render3d->sceneDir() . 'Pov/layout.php';

		$layoutTemplate = (empty($options['PovLayoutTemplate']))? $defaultLayoutFile : $options['PovLayoutTemplate'];

		// Extract the tpl vars so make it easier to use them in the template
		extract($tplVars);

		ob_start();

		require $layoutTemplate;

		return ob_get_clean();
	}

	/**
	 * Parse a set of cords to record min/max values for each dimension
	 * 
	 * @param array $triangle
	 * @return void
	 */
	protected function parseCords ($triangle) {
		foreach ($triangle as $v) {
			if (!isset($this->min['x']) || $v[0] < $this->min['x']) {
				$this->min['x'] = $v[0];
			}
			if (!isset($this->min['y']) || $v[1] < $this->min['y']) {
				$this->min['y'] = $v[1];
			}
			if (!isset($this->min['z']) || $v[2] < $this->min['z']) {
				$this->min['z'] = $v[2];
			}
			
			if (!isset($this->max['x']) || $v[0] > $this->max['x']) {
				$this->max['x'] = $v[0];
			}
			if (!isset($this->max['y']) || $v[1] > $this->max['y']) {
				$this->max['y'] = $v[1];
			}
			if (!isset($this->max['z']) || $v[2] > $this->max['z']) {
				$this->max['z'] = $v[2];
			}
		}
	}
}