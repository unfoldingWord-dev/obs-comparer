<?php
/***
 * Author: Richard Mahn <rich@themahn.com>
 * Description:
 * This script pulls the contents from the obs.catalog.json file to generate a list of languages that OBS is currently serving, and
 * presents a form to the user to select which source language and which target language(s) to get information on.
 *
 * Once the user selects the languages, the obs-<language>.json file is read for that language and an array of stats on the length of
 * the whole collection of chapters, each chapter and each frame are computed. Then it determines how much the length of each
 * frame of the source and target language differ from each other, and how then how much each target language frame's source/target variance
 * differs from the median variance.
 */
$source = (isset($_GET['s'])?$_GET['s']:'en');
$target = (isset($_GET['t'])?$_GET['t']:'');
$ignore_ws = (isset($_GET['ignore_ws'])?true:false);
$ignore_punct = (isset($_GET['ignore_punct'])?true:false);
$json = (isset($_GET['json'])?true:false);

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


$data = array();

if($source && $target){
	populate_data($source);
	get_stats($source);

	if($target == 'ALL'){
		foreach($languages as $language) {
			populate_data($language);
			get_stats($language);
			collate_with_source($language);
		}
	}
	else {
		populate_data($target);
		get_stats($target);
		collate_with_source($target);
	}

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
	$langData['stats']['chapterCount'] = array();
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

			$frame['text'] = $text;
			$frame['stats']['count'] = $length;
			$chapter['stats']['frameCount'][]  = $length;
			$langData['stats']['frameCount'][] = $length;
			$chapter['stats']['count'] += $length;
			$langData['stats']['count'] += $length;
		}

		$langData['stats']['chapterCount'][] = $chapter['stats']['count'];
	}

	// Now get stats

	$arr = $langData['stats']['chapterCount'];
	sort($arr);
	$langData['stats']['chapterLow'] = $arr[0];
	$langData['stats']['chapterMedian'] = calculate_median($arr);
	$langData['stats']['chapterAverage'] = calculate_average($arr);
	$langData['stats']['chapterHigh'] = end($arr);

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

	$srcData = $data[$source];
	$tarData = &$data[$language];

	unset($frameLowVar);
	unset($frameHighVar);
	unset($chapterLowVar);
	unset($chapterHighVar);
	$sourceTargetVariances = array();
	foreach($tarData['stats']['frameCount'] as $index=>$count){
		if( isset($srcData['stats']['frameCount'][$index]) && $srcData['stats']['frameCount'][$index] > 0) {
			$sourceTargetVariance = $count / $srcData['stats']['frameCount'][$index];
		}
		else {
			$sourceTargetVariance = 0;
		}

		if(! isset($frameLowVar) || $sourceTargetVariance < $frameLowVar){
			$frameLow = $count;
			$frameLowSource = $srcData['stats']['frameCount'][$index];
			$frameLowVar = $sourceTargetVariance;
		}

		if(! isset($frameHighVar) || $sourceTargetVariance > $frameHighVar){
			$frameHigh = $count;
			$frameHighSource = $srcData['stats']['frameCount'][$index];
			$frameHighVar = $sourceTargetVariance;
		}

		$sourceTargetVariances[] = $sourceTargetVariance;
	}
	$frameMedianVar = calculate_median($sourceTargetVariances);
	$frameAverageVar = calculate_average($sourceTargetVariances);

	$sourceTargetVariances = array();
	foreach($tarData['stats']['chapterCount'] as $index=>$count){
		if( $count > 0) {
			$sourceTargetVariance = $count / $srcData['stats']['chapterCount'][$index];
		}
		else {
			$sourceTargetVariance = 0;
		}
		
		if(! isset($chapterLowVar) || $sourceTargetVariance < $chapterLowVar){
			$chapterLow = $count;
			$chapterLowSource = $srcData['stats']['chapterCount'][$index];
			$chapterLowVar = $sourceTargetVariance;
		}

		if(! isset($chapterHighVar) || $sourceTargetVariance > $chapterHighVar){
			$chapterHigh = $count;
			$chapterHighSource = $srcData['stats']['chapterCount'][$index];
			$chapterHighVar = $sourceTargetVariance;
		}

		$sourceTargetVariances[] = $sourceTargetVariance;
	}
	$chapterMedianVar = calculate_median($sourceTargetVariances);
	$chapterAverageVar = calculate_average($sourceTargetVariances);

	$tarData['stats']['countSource'] = $srcData['stats']['count'];
	$tarData['stats']['countVar'] = $tarData['stats']['count'] / $srcData['stats']['count'];

	$tarData['stats']['chapterLow'] = $chapterLow;
	$tarData['stats']['chapterLowSource'] = $chapterLowSource;
	$tarData['stats']['chapterLowVar'] = $chapterLowVar;

	$tarData['stats']['chapterMedianSource'] = $srcData['stats']['chapterMedian'];
	$tarData['stats']['chapterMedianVar'] = $chapterMedianVar;

	$tarData['stats']['chapterAverageSource'] = $srcData['stats']['chapterAverage'];
	$tarData['stats']['chapterAverageVar'] = $chapterAverageVar;

	$tarData['stats']['chapterHigh'] = $chapterHigh;
	$tarData['stats']['chapterHighSource'] = $chapterHighSource;
	$tarData['stats']['chapterHighVar'] = $chapterHighVar;

	$tarData['stats']['frameLow'] = $frameLow;
	$tarData['stats']['frameLowSource'] = $frameLowSource;
	$tarData['stats']['frameLowVar'] = $frameLowVar;

	$tarData['stats']['frameMedianSource'] = $srcData['stats']['frameMedian'];
	$tarData['stats']['frameMedianVar'] = $frameMedianVar;

	$tarData['stats']['frameAverageSource'] = $srcData['stats']['frameAverage'];
	$tarData['stats']['frameAverageVar'] = $frameAverageVar;

	$tarData['stats']['frameHigh'] = $frameHigh;
	$tarData['stats']['frameHighSource'] = $frameHighSource;
	$tarData['stats']['frameHighVar'] = $frameHighVar;

	$median = $tarData['stats']['frameMedianVar'];

	foreach($tarData['chapters'] as $chapterIndex=>&$chapter){
		unset($frameLowVar);
		unset($frameHighVar);
		$sourceTargetVariances = array();
		foreach($chapter['stats']['frameCount'] as $index=>$count){
			if( isset($srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index]) && $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index] > 0) {
				$sourceTargetVariance = $count / $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
			}
			else {
				$sourceTargetVariance = 0;
			}

			if(! isset($frameLowVar) || $sourceTargetVariance < $frameLowVar){
				$frameLow = $count;
				$frameLowSource = $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
				$frameLowVar = $sourceTargetVariance;
			}

			if(! isset($frameHighVar) || $sourceTargetVariance > $frameHighVar){
				$frameHigh = $count;
				$frameHighSource = $srcData['chapters'][$chapterIndex]['stats']['frameCount'][$index];
				$frameHighVar = $sourceTargetVariance;
			}

			$sourceTargetVariances[] = $sourceTargetVariance;
		}
		$frameMedianVar = calculate_median($sourceTargetVariances);
		$frameAverageVar = calculate_average($sourceTargetVariances);

		$chapter['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['stats']['count'];
		$chapter['stats']['countVar'] = $chapter['stats']['count'] / $srcData['chapters'][$chapterIndex]['stats']['count'];

		$chapter['stats']['frameLow'] = $frameLow;
		$chapter['stats']['frameLowSource'] = $frameLowSource;
		$chapter['stats']['frameLowVar'] = $frameLowVar;

		$chapter['stats']['frameMedianSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameMedian'];
		$chapter['stats']['frameMedianVar'] = $frameMedianVar;

		$chapter['stats']['frameAverageSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameAverage'];
		$chapter['stats']['frameAverageVar'] = $frameAverageVar;

		$chapter['stats']['frameHigh'] = $frameHigh;
		$chapter['stats']['frameHighSource'] = $frameHighSource;
		$chapter['stats']['frameHighVar'] = $frameHighVar;

		foreach($chapter['frames'] as $frameIndex=>&$frame){
			$frame['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];
			$frame['stats']['countVar'] = $frame['stats']['count'] / $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];
		}
	}
}
?>

<html>
<head>
	<title>Language Comparison & Breakdown</title>
	<meta charset='utf-8'>
	<script src="https://code.jquery.com/jquery-2.1.3.min.js"></script>

	<style type="text/css">
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
			width: 200px;
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
</head>
<body>

<h3>Language Comparison & Breakdown</h3>
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

</form>

<?php if(! empty($data) && $target && $source):?>
	<div class="clear">
		<?php foreach($data as $language=>$info):
			if($language == $source)
				continue;

			$lowestVar = $info['stats']['frameMedianVar'] - .2;
			$highestVar = $info['stats']['frameMedianVar'] + .2;
			?>
			<div class="language" id="<?php echo $language?>">
				<div class="container">
					<div class="heading">Target: <?php echo $catalog[$language]['string'].' ('.$language.')'?></div>
					<div class="item clear-left break">Char Count: <?php echo number_format($info['stats']['count'])?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['countSource'])?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['countVar'] * 100, 2)?>%</div>
					<!--
					<div class="item clear-left break">Chapter Low: <?php echo number_format($info['stats']['chapterLow'])?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['chapterLowSource'])?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['chapterLowVar'] * 100, 2)?>%</div>
					<div class="item clear-left">Chapter High: <?php echo number_format($info['stats']['chapterHigh'])?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['chapterHighSource'])?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['chapterHighVar'] * 100, 2)?>%</div>
					<div class="item clear-left">Chapter Median: <?php echo number_format($info['stats']['chapterMedian'], 2)?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['chapterMedianSource'], 2)?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['chapterMedianVar'] * 100, 2)?>%</div>
					<div class="item clear-left">Chapter Average: <?php echo number_format($info['stats']['chapterAverage'], 2)?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['chapterAverageSource'], 2)?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['chapterAverageVar'] * 100, 2)?>%</div>
					-->
					<div class="item clear-left"><span style="font-weight:bold;">Median Variance: <?php echo number_format($info['stats']['frameMedianVar'] * 100, 2)?>%</span></div>
					<div class="item">Target: <?php echo number_format($info['stats']['frameMedian'])?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['frameMedianSource'])?></div>
					<div class="item clear-left<?php echo ($info['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Lowest Variance: <?php echo number_format($info['stats']['frameLowVar'] * 100, 2)?>%</div>
					<div class="item<?php echo ($info['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Target: <?php echo number_format($info['stats']['frameLow'])?></div>
					<div class="item<?php echo ($info['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Source: <?php echo number_format($info['stats']['frameLowSource'])?></div>
					<div class="item clear-left<?php echo ($info['stats']['frameHighVar']>$highestVar?' warning':'')?>">Highest Variance: <?php echo number_format($info['stats']['frameHighVar'] * 100, 2)?>%</div>
					<div class="item<?php echo ($info['stats']['frameHighVar']>$highestVar?' warning':'')?>">Target: <?php echo number_format($info['stats']['frameHigh'])?></div>
					<div class="item<?php echo ($info['stats']['frameHighVar']>$highestVar?' warning':'')?>">Source: <?php echo number_format($info['stats']['frameHighSource'])?></div>
					<!--
					<div class="item clear-left">Frame Average: <?php echo number_format($info['stats']['frameAverage'], 2)?></div>
					<div class="item">Source: <?php echo number_format($info['stats']['frameAverageSource'], 2)?></div>
					<div class="item">Variance: <?php echo number_format($info['stats']['frameAverageVar'] * 100, 2)?>%</div>
					-->
					<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
				</div>

				<div class="chapters" id="<?php echo $language?>-chapters" style="display:none">
					<?php foreach($info['chapters'] as $chapterIndex=>$chapter):?>
						<div class="chapter" id="<?php echo $language?>-chapter-<?php echo $chapter['number']?>">
							<div class="container">
								<div class="heading">Chapter: <?php echo $chapter['title']?></div>
								<div class="item clear-left">Char Count: <?php echo number_format($chapter['stats']['count'])?></div>
								<div class="item">Source: <?php echo number_format($chapter['stats']['countSource'])?></div>
								<div class="item">Variance: <?php echo number_format($chapter['stats']['countVar'] * 100, 2)?>%</div>
								<!--
								<div class="item clear-left">Median Variance: <?php echo number_format($chapter['stats']['frameMedianVar'] * 100, 2)?>%</div>
								<div class="item">Target: <?php echo number_format($chapter['stats']['frameMedian'])?></div>
								<div class="item">Source: <?php echo number_Format($chapter['stats']['frameMedianSource'])?></div>
								-->
								<div class="item clear-left<?php echo ($chapter['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Lowest Variance: <?php echo number_format($chapter['stats']['frameLowVar'] * 100, 2)?>%</div>
								<div class="item<?php echo ($chapter['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Target: <?php echo number_format($chapter['stats']['frameLow'])?></div>
								<div class="item<?php echo ($chapter['stats']['frameLowVar']<$lowestVar?' warning':'')?>">Source: <?php echo number_format($chapter['stats']['frameLowSource'])?></div>
								<div class="item clear-left<?php echo ($chapter['stats']['frameHighVar']>$highestVar?' warning':'')?>">Highest Variance: <?php echo number_format($chapter['stats']['frameHighVar'] * 100, 2)?>%</div>
								<div class="item<?php echo ($chapter['stats']['frameHighVar']>$highestVar?' warning':'')?>">Target: <?php echo number_format($chapter['stats']['frameHigh'])?></div>
								<div class="item<?php echo ($chapter['stats']['frameHighVar']>$highestVar?' warning':'')?>">Source: <?php echo number_format($chapter['stats']['frameHighSource'])?></div>
								<!--
								<div class="item clear-left">Frame Average: <?php echo number_format($chapter['stats']['frameAverage'], 2)?></div>
								<div class="item">Source: <?php echo number_format($chapter['stats']['frameAverageSource'], 2)?></div>
								<div class="item">Variance: <?php echo number_format($chapter['stats']['frameAverageVar'] * 100, 2)?>%</div>
								-->
								<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
							</div>

							<div class="frames" id="<?php echo $language?>-<?php echo $chapter['number']?>-frames" style="display:none">
								<?php foreach($chapter['frames'] as $frameIndex=>$frame):?>
									<div class="frame" id="<?php echo $language?>-frame-<?php echo $frame['id']?>">
										<div class="container<?php echo ($frame['stats']['countVar']<$lowestVar||$frame['stats']['countVar']>$highestVar?' warning':'')?>">
											<div class="heading">Frame: <?php echo $frame['id']?></div>
											<div class="item clear-left">Char Count: <?php echo number_format($frame['stats']['count'])?></div>
											<div class="item">Source: <?php echo number_format($frame['stats']['countSource'])?></div>
											<div class="item">Variance: <?php echo number_format($frame['stats']['countVar'] * 100, 2)?>%</div>
											<div class="item toggle-container"><a href="#" class="toggle">▼</a></div>
										</div>

										<div class="sentences clear-left" id="<?php echo $language?>-frame-<?php echo $frame['id']?>-sentences" style="display:none;">
											<img src="<?php echo $frame['img']?>">
											<p>
												<?php echo $source?>:<br/>
											<pre><?php echo $data[$source]['chapters'][$chapterIndex]['frames'][$frameIndex]['text']?></pre>
											</p>
											<p>
												<?php echo $language?>:<br/>
											<pre><?php echo $frame['text']?></pre>
											</p>
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
<?php endif; ?>

<div class="clear">
	<hr/>
	<div class="heading">Description:</div>
	<p>
		This tool uses the json files located at <a href="https://api.unfoldingword.org/obs/txt/1/">https://api.unfoldingword.org/obs/txt/1/</a>
		to determine if the text of a frame of a given target language falls within plus or minus 20% of the normal variance from the source language.
	</p>
	The length of each frame's text of both source and target language will be added up, and the percentage of variance between source and target will be determined
	by
	<ul>
		&lt;target length&gt; / &lt;source length&gt;
	</ul>
	The median variance is then found from all the frames. Each frame's source-target variance is then compared
	to the median and if their variance is more or less than 20%, then that frame is highlighted in red. Lowest and highest
	variance is also shown for each chapter, and highlighted in red if it is plus or minus 20%.
</div>

</body>
</html>

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
