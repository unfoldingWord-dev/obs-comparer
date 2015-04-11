<?php
/***
 * Author: Richard Mahn <rich@themahn.com>
 * Description:
 * This script pulls the contents from the obs.catalog.json file to generate a list of languages that OBS is currently serving, and
 * presents a form to the user to select which source language and which target language(s) to get information on.
 *
 * Once the user selects the languages, the obs-<language>.json file is read for that language and an array of stats on the length of
 * the whole collection of chapters, each chapter and each frame are computed. Then it determines how much the length of each
 * frame of the source and target language differ from each other, and how then how much each target language frame's source/target ratio
 * differs from the median ratio.
 */

require_once('vendor/autoload.php');

use Stichoza\GoogleTranslate\TranslateClient;

$source = (isset($_GET['s'])?$_GET['s']:'en');
$target = (isset($_GET['t'])?$_GET['t']:'');
$ignore_ws = (isset($_GET['ignore_ws'])?true:false);
$ignore_punct = (isset($_GET['ignore_punct'])?true:false);
$json = (isset($_GET['json'])?true:false);
$googleTranslate = (isset($_GET['translate'])?true:false);

$catalogJson = json_decode(file_get_contents('https://api.unfoldingword.org/obs/txt/1/obs-catalog.json'), true);
$catalog = array();
$languages = array();
foreach($catalogJson as $data){
	$catalog[$data['language']] = $data;
	$languages[] = $data['language'];
}

if(! in_array($source, $languages)){
	$source = 'en';
	$target = '';
}
if($target && ! in_array($target, $languages) && $target != 'ALL'){
	$target = '';
}

$googleTranslationMap = array();

$data = array();

if($source && $target){
	load_google_translation_map();

	populate_data($source);
	get_stats($source);

	if($target == 'ALL'){
		foreach($languages as $language) {
			if($language != $source) {
				populate_data($language);
				get_stats($language);
				collate_with_source($language);
			}
		}
	}
	else {
		populate_data($target);
		get_stats($target);
		collate_with_source($target);
	}

	save_google_translation_map();
	
	if($json){
		echo json_encode($data);
		exit;
	}
}

function populate_data($language)
{
	global $data;

	if(! isset($data[$language])) {
		$url = "https://api.unfoldingword.org/obs/txt/1/$language/obs-$language.json";
		$content = file_get_contents($url);
		$data[$language] = json_decode($content, true);
	}
}

function get_stats($language){
	global $data, $ignore_punct, $ignore_ws;

	$langData = &$data[$language];

	$langData['stats']['count'] = 0;
	$langData['stats']['frameCount'] = array();

	foreach($langData['chapters'] as $chapterIndex=>&$chapter){
		$chapter['stats']['count'] = 0;
		$chapter['stats']['frameCount'] = array();

		foreach($chapter['frames'] as $frameIndex=>&$frame){
			$text = $frame['text'];

			if($ignore_punct){
				$text = preg_replace("/\p{P}/u", "", $text);
			}

			if($ignore_ws) {
				$text = preg_replace('/\s+/', '', $text);
			}

			$length = mb_strlen($text, 'UTF-8');

			$frame['transformedText'] = $text;
			$frame['stats']['count'] = $length;
			$chapter['stats']['frameCount'][]  = $length;
			$langData['stats']['frameCount'][] = $length;
			$chapter['stats']['count'] += $length;
			$langData['stats']['count'] += $length;
		}
	}

	// Now get stats
	$arr = $langData['stats']['frameCount'];
	sort($arr);
	$langData['stats']['frameLow'] = $arr[0];
	$langData['stats']['frameMedian'] = calculate_median($arr);
	$langData['stats']['frameAverage'] = calculate_average($arr);
	$langData['stats']['frameHigh'] = end($arr);

	foreach($langData['chapters'] as $chapterIndex=>&$chapter){
		$arr = $chapter['stats']['frameCount'];
		sort($arr);
		$chapter['stats']['frameLow'] = $arr[0];
		$chapter['stats']['frameMedian'] = calculate_median($arr);
		$chapter['stats']['frameAverage'] = calculate_average($arr);
		$chapter['stats']['frameHigh'] = end($arr);
	}
}

function calculate_median($arr) {
	sort($arr);
	$count = count($arr); //total numbers in array
	$middleval = (int)floor(($count-1)/2); // find the middle value, or the lowest middle value
	if($count % 2) { // odd number, middle is the median
		$median = $arr[$middleval];
	} else { // even number, calculate avg of 2 medians
		$low = $arr[$middleval];
		$high = $arr[$middleval+1];
		$median = (($low+$high)/2);
	}
	return $median;
}

function calculate_average($arr) {
	$count = count($arr); //total numbers in array
	$total = 0;
	foreach ($arr as $value) {
		$total = $total + $value; // total value of array numbers
	}
	$average = ($total/$count); // get average value
	return $average;
}

function collate_with_source($language){
	global $source;
	global $data;
	global $googleTranslate;
	global $googleTranslationMap;

	$srcData = $data[$source];
	$tarData = &$data[$language];

	unset($frameLowRatio);
	unset($frameHighRatio);
	unset($chapterLowRatio);
	unset($chapterHighRatio);
	$sourceTargetRatios = array();
	foreach($tarData['stats']['frameCount'] as $index=>$count){
		if( isset($srcData['stats']['frameCount'][$index]) && $srcData['stats']['frameCount'][$index] > 0) {
			$sourceTargetRatio = $count / $srcData['stats']['frameCount'][$index];
		}
		else {
			$sourceTargetRatio = 0;
		}

		if(! isset($frameLowRatio) || $sourceTargetRatio < $frameLowRatio){
			$frameLow = $count;
			$frameLowSource = $srcData['stats']['frameCount'][$index];
			$frameLowRatio = $sourceTargetRatio;
		}

		if(! isset($frameHighRatio) || $sourceTargetRatio > $frameHighRatio){
			$frameHigh = $count;
			$frameHighSource = $srcData['stats']['frameCount'][$index];
			$frameHighRatio = $sourceTargetRatio;
		}

		$sourceTargetRatios[] = $sourceTargetRatio;
	}
	$frameMedianRatio = calculate_median($sourceTargetRatios);
	$frameAverageRatio = calculate_average($sourceTargetRatios);

	$tarData['stats']['countSource'] = $srcData['stats']['count'];
	$tarData['stats']['countRatio'] = $tarData['stats']['count'] / $srcData['stats']['count'];

	$tarData['stats']['frameLow'] = $frameLow;
	$tarData['stats']['frameLowSource'] = $frameLowSource;
	$tarData['stats']['frameLowRatio'] = $frameLowRatio;

	$tarData['stats']['frameMedianSource'] = $srcData['stats']['frameMedian'];
	$tarData['stats']['frameMedianRatio'] = $frameMedianRatio;

	$tarData['stats']['frameAverageSource'] = $srcData['stats']['frameAverage'];
	$tarData['stats']['frameAverageRatio'] = $frameAverageRatio;

	$tarData['stats']['frameHigh'] = $frameHigh;
	$tarData['stats']['frameHighSource'] = $frameHighSource;
	$tarData['stats']['frameHighRatio'] = $frameHighRatio;

	$median = $tarData['stats']['frameMedianRatio'];

	foreach($tarData['chapters'] as $chapterIndex=>&$chapter){
		unset($frameLowRatio);
		unset($frameHighRatio);
		$sourceTargetRatios = array();
		foreach($chapter['stats']['frameCount'] as $index=>$count){
			if( isset($srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index]) && $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index] > 0) {
				$sourceTargetRatio = $count / $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
			}
			else {
				$sourceTargetRatio = 0;
			}

			if(! isset($frameLowRatio) || $sourceTargetRatio < $frameLowRatio){
				$frameLow = $count;
				$frameLowSource = $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
				$frameLowRatio = $sourceTargetRatio;
			}

			if(! isset($frameHighRatio) || $sourceTargetRatio > $frameHighRatio){
				$frameHigh = $count;
				$frameHighSource = $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
				$frameHighRatio = $sourceTargetRatio;
			}

			$sourceTargetRatios[] = $sourceTargetRatio;
		}
		$frameMedianRatio = calculate_median($sourceTargetRatios);
		$frameAverageRatio = calculate_average($sourceTargetRatios);

		$chapter['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['stats']['count'];
		$chapter['stats']['countRatio'] = $chapter['stats']['count'] / $srcData['chapters'][$chapterIndex]['stats']['count'];

		$chapter['stats']['frameLow'] = $frameLow;
		$chapter['stats']['frameLowSource'] = $frameLowSource;
		$chapter['stats']['frameLowRatio'] = $frameLowRatio;

		$chapter['stats']['frameMedianSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameMedian'];
		$chapter['stats']['frameMedianRatio'] = $frameMedianRatio;

		$chapter['stats']['frameAverageSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameAverage'];
		$chapter['stats']['frameAverageRatio'] = $frameAverageRatio;

		$chapter['stats']['frameHigh'] = $frameHigh;
		$chapter['stats']['frameHighSource'] = $frameHighSource;
		$chapter['stats']['frameHighRatio'] = $frameHighRatio;


		$tr = new TranslateClient($language, $source);
		$ratio = $tarData['stats']['frameMedianRatio'];
		$lowestRatio = $ratio - .2;
		$highestRatio = $ratio + .2;

		foreach($chapter['frames'] as $frameIndex=>&$frame){
			$frame['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];
			$frame['stats']['countRatio'] = $frame['stats']['count'] / $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];

			if($googleTranslate && $language != 'am' && ($frame['stats']['countRatio'] < $lowestRatio || $frame['stats']['countRatio'] > $highestRatio)) {
				if(isset($googleTranslationMap[$language][$frame['text']])){
					$frame['googleTranslate'] = $googleTranslationMap[$language][$frame['text']];
				}
				else {
					$gt = $tr->translate($frame['text']);
					$gt = preg_replace('/ ([\.,\?\!])/', '${1}', $gt); // for some reason translate() put spaces before punctuation
					$gt = preg_replace('/^([^"]*)" /', '${1}"', $gt);
					$frame['googleTranslate'] = $gt;
					$googleTranslationMap[$language][$frame['text']] = $gt;
				}
			}
		}
	}
}

function load_google_translation_map(){
	global $googleTranslationMap;

	if(file_exists('google_translation_map.txt')){
		$serializedData = file_get_contents('google_translation_map.txt');
		$googleTranslationMap = unserialize($serializedData);
	}
	else {
		$googleTranslationMap = array();
	}
}

function save_google_translation_map(){
	global $googleTranslationMap;

	$serializedData = serialize($googleTranslationMap);
	file_put_contents('google_translation_map.txt', $serializedData);
}
?>

<html>
<head>
	<title>Language Comparison & Breakdown</title>
	<meta charset='utf-8'>
	<script src="http://code.jquery.com/jquery-2.1.3.min.js"></script>

	<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">

	<style type="text/css">
		a {
			color: #428bca;
			text-decoration: none;
		}

		div {
			overflow: auto;
		}

		.language {
			clear: both;
			padding-left: 10px;
			padding-top: 5px;
			padding-bottom: 10px;
		}
		.chapters {
			padding-left: 20px;
			padding-bottom: 10px;
		}
		.frames {
			padding-left: 30px;
			padding-bottom: 10px;
		}

		.chapter {
			padding-top: 5px;
			clear: both;
		}
		.frame {
			padding-top: 5px;
			clear: both;
		}

		.heading {
			font-weight: bold;
			font-size: 1.2em;
			width: 700px;
		}

		.item {
			float: left;
			padding-right: 15px;
		}

		.toggle-container {
			width: auto;
		}

		a.toggle {
			text-decoration: none;
			color: black;
		}

		.clear-left {
			clear: left;
		}

		.clear {
			clear: both;
		}

		.warning {
			color: #b94a48;
			background-color: #f2dede;
			border-color: #eed3d7;
		}
	</style>


	<script type="text/javascript">
		$(document).ready(function(){
			$('.language > .container > .toggle-container > a.toggle').click(function(){
				$(this).parents('.container').first().siblings('.chapters').toggle();
				if($(this).html() == "▲")
					$(this).html("▼");
				else
					$(this).html("▲");
				return false;
			});

			$('.chapter > .container >  .toggle-container > a.toggle').click(function(){
				$(this).parents('.container').first().siblings('.frames').toggle();
				if($(this).html() == "▲")
					$(this).html("▼");
				else
					$(this).html("▲");
				return false;
			});

			$('.frame > .container >  .toggle-container > a.toggle').click(function(){
				$(this).parents('.container').first().siblings('.sentences').toggle();
				if($(this).html() == "▲")
					$(this).html("▼");
				else
					$(this).html("▲");
				return false;
			});
		});
	</script>
</head>
<body>

<h3>Language Comparison & Breakdown</h3>

<?php if($target == "am" || $target == "ALL" && $googleTranslate):?>
<div class="warning">
	Google translate does not translate አማርኛ (am). Sorry. <?php if($target == "ALL"):?>Other languages will be translated.<?php endif;?>
</div>
<?php endif; ?>

<form method="get">
	Source:
	<select id="source-language" name="s">
		<?php foreach($languages as $language):?>
			<option value="<?php echo $language?>"<?php echo ($language==$source?' selected="selected"':'')?>><?php echo $catalog[$language]['string'].' ('.$language.')'?></option>
		<?php endforeach;?>
	</select>

	Target:
	<select id="target-language" name="t">
		<option value="ALL">All</option>
		<?php foreach($languages as $language):?>
			<option value="<?php echo $language?>"<?php echo ($language==$target?' selected="selected"':'')?>><?php echo $catalog[$language]['string'].' ('.$language.')'?></option>
		<?php endforeach;?>
	</select>

	<input type="submit" value="Submit"/>

	<br/>

	<input type="checkbox" name="ignore_ws" value="1"<?php echo ($ignore_ws?' checked="checked"':'')?>> Ignore spaces
	<input type="checkbox" name="ignore_punct" value="1"<?php echo ($ignore_punct?' checked="checked"':'')?>> Ignore punctuation
	<input type="checkbox" name="json" value="1"<?php echo ($json?' checked="checked"':'')?>> Output as JSON

	<br/>

	<input type="checkbox" name="translate" value="1"<?php echo ($googleTranslate?' checked="checked"':'')?>> Use Google Translate (only on large variations, takes time!)
</form>

<?php if(! empty($data) && $target && $source):?>
	<div class="clear">
		<?php foreach($data as $language=>$info):
			if($language == $source)
				continue;

			$ratio = $info['stats']['frameMedianRatio'];
			$lowestRatio = $ratio - .2;
			$highestRatio = $ratio + .2;
			?>
			<div class="language" id="<?php echo $language?>">
				<div class="container">
					<div class="heading">Target: <?php echo $catalog[$language]['string'].' ('.$language.')'?> <a href="https://door43.org/<?php echo $target?>/obs/" style="text-decoration:none;font-size:.8em;font-weight:normal;" target="_blank"><i class="fa fa-external-link"></i></a></span></div>
					<div class="item clear-left break">Overall Character Count: <?php echo number_format($info['stats']['count'])?> (Target), <?php echo number_format($info['stats']['countSource'])?> (Source)</div>
					<div class="item">Ratio: <?php echo sprintf("%.2f",$info['stats']['countRatio'] * 100)?>%</div>
					<div class="item clear-left"><span style="font-weight:bold;">Median Ratio: <?php echo sprintf("%.2f",$info['stats']['frameMedianRatio'] * 100)?>% <== This ratio will be used to find frames with a variance > 20%</span></div>
					<div class="item clear-left"><span style="font-weight:normal;">Average Ratio: <?php echo sprintf("%.2f",$info['stats']['frameAverageRatio'] * 100)?>%</span></div>
					<div class="item clear-left<?php echo ($info['stats']['frameLowRatio']<$lowestRatio?' warning':'')?>">Lowest Ratio: <?php echo sprintf("%.2f",$info['stats']['frameLowRatio'] * 100)?>% (<?php echo number_format($info['stats']['frameLow'])?>:<?php echo number_format($info['stats']['frameLowSource'])?>)</div>
					<div class="item<?php echo ($info['stats']['frameLowRatio']<$lowestRatio?' warning':'')?>">Variance: <?php echo sprintf("%+.2f",($info['stats']['frameLowRatio'] - $ratio) * 100)?>%</div>
					<div class="item clear-left<?php echo ($info['stats']['frameHighRatio']>$highestRatio?' warning':'')?>">Highest Ratio: <?php echo sprintf("%.2f",$info['stats']['frameHighRatio'] * 100)?>% (<?php echo number_format($info['stats']['frameHigh'])?>:<?php echo number_format($info['stats']['frameHighSource'])?>)</div>
					<div class="item<?php echo ($info['stats']['frameHighRatio']>$highestRatio?' warning':'')?>">Variance: <?php echo sprintf("%+.2f",($info['stats']['frameHighRatio'] - $ratio) * 100)?>%</div>
					<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
				</div>

				<div class="chapters" id="<?php echo $language?>-chapters" style="display:none">
					<?php foreach($info['chapters'] as $chapterIndex=>$chapter):?>
						<div class="chapter" id="<?php echo $language?>-chapter-<?php echo $chapter['number']?>">
							<div class="container">
								<div class="heading">Chapter: <?php echo $chapter['title']?> <a href="https://door43.org/<?php echo $target?>/obs/<?php echo $chapter['number']?>" style="text-decoration:none;font-size:.8em;font-weight:normal;" target="_blank"><i class="fa fa-external-link"></i></a></div>
								<div class="item clear-left break">Chapter Character Count: <?php echo number_format($chapter['stats']['count'])?> (Target), <?php echo number_format($chapter['stats']['countSource'])?> (Source)</div>
								<div class="item">Ratio: <?php echo sprintf("%.2f",$chapter['stats']['countRatio'] * 100)?>%</div>
								<div class="item clear-left<?php echo ($chapter['stats']['frameLowRatio']<$lowestRatio?' warning':'')?>">Lowest Ratio: <?php echo sprintf("%.2f",$chapter['stats']['frameLowRatio'] * 100)?>% (<?php echo number_format($chapter['stats']['frameLow'])?>:<?php echo number_format($chapter['stats']['frameLowSource'])?>)</div>
								<div class="item<?php echo ($chapter['stats']['frameLowRatio']<$lowestRatio?' warning':'')?>">Variance: <?php echo sprintf("%+.2f",($chapter['stats']['frameLowRatio'] - $ratio) * 100)?>%</div>
								<div class="item clear-left<?php echo ($chapter['stats']['frameHighRatio']>$highestRatio?' warning':'')?>">Highest Ratio: <?php echo sprintf("%.2f",$chapter['stats']['frameHighRatio'] * 100)?>% (<?php echo number_format($chapter['stats']['frameHigh'])?>:<?php echo number_format($chapter['stats']['frameHighSource'])?>)</div>
								<div class="item<?php echo ($chapter['stats']['frameHighRatio']>$highestRatio?' warning':'')?>">Variance: <?php echo sprintf("%+.2f",($chapter['stats']['frameHighRatio'] - $ratio) * 100)?>%</div>
								<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
							</div>

							<div class="frames" id="<?php echo $language?>-<?php echo $chapter['number']?>-frames" style="display:none">
								<?php foreach($chapter['frames'] as $frameIndex=>$frame):?>
									<div class="frame" id="<?php echo $language?>-frame-<?php echo $frame['id']?>">
										<div class="container<?php echo ($frame['stats']['countRatio']<$lowestRatio||$frame['stats']['countRatio']>$highestRatio?' warning':'')?>">
											<div class="heading">Frame: <?php echo $frame['id']?> <a href="https://door43.org/<?php echo $target?>/obs/<?php echo $chapter['number']?>" style="text-decoration:none;font-size:.8em;font-weight:normal;" target="_blank"><i class="fa fa-external-link"></i></a></div>
											<div class="item clear-left">Ratio: <?php echo sprintf("%.2f",$frame['stats']['countRatio'] * 100)?>% (<?php echo number_format($frame['stats']['count'])?>:<?php echo number_format($frame['stats']['countSource'])?>)</div>
											<div class="item">Variance: <?php echo sprintf("%+.2f",($frame['stats']['countRatio'] - $ratio) * 100)?>%</div>
											<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
										</div>

										<div class="sentences clear-left" id="<?php echo $language?>-frame-<?php echo $frame['id']?>-sentences" style="display:none;">
											<img src="<?php echo $frame['img']?>" />
											<p>
												<?php echo $source?>:<br/>
											<pre><?php echo $data[$source]['chapters'][$chapterIndex]['frames'][$frameIndex]['transformedText']?></pre>
											</p>
											<p>
												<?php echo $language?>:<br/>
											<pre><?php echo $frame['transformedText']?></pre>
											</p>
											<?php if($frame['googleTranslate']):?>
											<p>
												Google Translate:<br/>
											<pre><?php echo $frame['googleTranslate'];?></pre>
											</p>
											<?php endif;?>
										</div>
									</div>
								<?php endforeach;?>
							</div>
						</div>
					<?php endforeach;?>
				</div>
			</div>
		<?php endforeach;?>
	</div>
<?php else: ?>

<div class="clear">
	<hr/>
	<div class="heading">Summary:</div>
	<p>
		This tool uses the json files located at <a href="https://api.unfoldingword.org/obs/txt/1/">https://api.unfoldingword.org/obs/txt/1/</a>
		to determine if the text of a frame of a given target language falls within ±20% of the normal percentage ratio between it and the source language.
	</p>
	<div class="heading">Description:</div>
	<p>
		The purpose of this tool is to determine if the text of a frame of OBS is outside the bounds of the normal ratio between the target language and
		the source language (usually English). This goes by the understanding that the text of most languages will normally longer or shorter than a sentence or paragraph in English.
	</p>
	<p>
		For example, in Chinese, something can be written in way fewer characters than English. "今天我要去超市买电脑。" is "Today I will go to the store to buy a computer.",
		the Chinese being 11 characters, the English being 47 characters, a ratio of 11:47, or a percentage ratio of 23.4%. (if you have it ignore punctuation and spaces,
		it is 10:36 or 27.77%). On the other hand, French sentences are usually longer than a sentence with the same meaning in English, having a little more than a 110%
		percent ratio.
	</p>
	<p>
		Once a source and target language is chosen, this tool will gather all the frames of both the source and target language, get the percentage ratio of each target frame
		with its corresponding source frame (target length / source length), and then from all those target/source ratios, select the percentage ratio that is the median.
		This is then used to determine the variance of a given frame is more or less than 20% of the target languages ratio with the source language.
	</p>
	<div class="heading">Notes/Concerns:</div>
	<ul>
		<li><b>Median, average, or...?</b> Is selecting the median from the pool of all frame target-source ratios the best way to get the normal ratio of a language?</li>
		<li><b>Longer vs. shorter text?</b> Is the ratio different when the source text is short (a short sentence) compared to when there is a lot of text (4-5 sentences?)</li>
		<li><b>Variance - what range?</b> Is ±20% the best variance to use to say if a text's translation should be re-evaluated? Should this be different for text that is short, or text that is long?</li>
		<li><b>Spaces and Punctuation?</b> Is it more reliable to ignore spaces and or punctuation for both source and target? Or better to keep them in? Or based on target language?
			Chinese has no spaces, and German often has very long phrases or words without spaces where English has spaces (e.g. Freundschaftsbezeigungen = demonstrations of friendship).</li>
	</ul>
</div>
<?php endif; ?>

</body>
</html>
