<?

require 'vendor/autoload.php';

$config = parse_ini_file('/repos/config/movies.ini');
$api_key = $config['rottentomatoes_key'];
$all_directors = [];
$all_cast = [];
$movies = [];

$getMovie = function($request, $curlTomatoes) use (&$all_cast, &$all_directors, &$movies, $api_key) {
	// Artificially stagger calls because of Rotten Tomatoes' limit of 5 calls.
	// So worst-case scenario, 5 simultaneous calls are made in 1 second.
	sleep(1);

	$payload = json_decode($request->getResponseText());
	if ($payload === false) {
		return;
	}

	switch ($request->getExtraInfo()) {
		case 'movie': 
			// For now, auto-select the first result
			$movie_id = $payload->movies[0]->id;

			if (is_numeric($movie_id) && empty($movies[$movie_id])) {
				$movies[$movie_id] = true;
			} else {
				// Either no movie found or movie already parsed
				return;
			}

			// Lookup director
			$request = new RollingCurl\Request("http://api.rottentomatoes.com/api/public/v1.0/movies/$movie_id.json?apikey=$api_key");
			$request->setExtraInfo('director');
			$curlTomatoes->add($request);

			// Lookup cast
			$request = new RollingCurl\Request("http://api.rottentomatoes.com/api/public/v1.0/movies/$movie_id/cast.json?apikey=$api_key");
			$request->setExtraInfo('cast');
			$curlTomatoes->add($request);
			break;
		case 'director': 
			foreach ($payload->abridged_directors as $director) {
				if (empty($all_directors[$director->name])) {
					$all_directors[$director->name] = 1;
				} else {
					$all_directors[$director->name]++;
				}
			}
			break;
		case 'cast':
			foreach ($payload->cast as $caster) {
				if (empty($all_cast[$caster->name])) {
					$all_cast[$caster->name] = 1;
				} else {
					$all_cast[$caster->name]++;
				}
			}
			break;
	}
};

$curlTomatoes = new RollingCurl\RollingCurl();
$curlTomatoes->setCallback($getMovie);
$curlTomatoes->setSimultaneousLimit(5);

$fh = fopen('movielist.txt', 'r');
while ($line = fgets($fh) !== false) {
	// Lookup movie
	$title = urlencode($line);
	$request = new RollingCurl\Request("http://api.rottentomatoes.com/api/public/v1.0/movies.json?q=$title&page_limit=1&apikey=$api_key");
	$request->setExtraInfo('movie');
	$curlTomatoes->add($request);
}

// Let death by pelting begin
$curlTomatoes->execute();

// Put the count together with the entry
$parse = function(&$value, $key) {
	$value = "($value) $key";
};

array_walk($all_directors, $parse);
array_walk($all_cast, $parse);

file_put_contents('directors.txt', implode(PHP_EOL, $all_directors));
file_put_contents('cast.txt', implode(PHP_EOL, $all_cast));