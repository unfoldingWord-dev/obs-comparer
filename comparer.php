<?php
$source = (isset($_GET['s'])?$_GET['s']:'en');
$target = (isset($_GET['t'])?$_GET['t']:'');
$ignore_ws = (isset($_GET['ignore_ws'])?true:false);
$ignore_punct = (isset($_GET['ignore_punct'])?true:false);
$json = (isset($_GET['json'])?true:false);

$languages = array('am', 'en', 'es', 'fr', 'pt-br', 'ru');

if(! in_array($source, $languages)){
	$source = 'en';
	$target = '';
}
if($target && ! in_array($target, $languages) && $target != 'ALL'){
	$target = '';
}

$catalogJson = json_decode(file_get_contents('https://api.unfoldingword.org/obs/txt/1/obs-catalog.json'), true);
$catalog = array();
foreach($catalogJson as $data){
	$catalog[$data['language']] = $data;
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

	$tarData['stats']['countSource'] = $srcData['stats']['count'];
	$tarData['stats']['countVar'] = $tarData['stats']['count'] / $srcData['stats']['count'];

	$tarData['stats']['chapterLowSource'] = $srcData['stats']['chapterLow'];
	$tarData['stats']['chapterLowVar'] = $tarData['stats']['chapterLow'] / $srcData['stats']['chapterLow'];

	$tarData['stats']['chapterMedianSource'] = $srcData['stats']['chapterMedian'];
	$tarData['stats']['chapterMedianVar'] = $tarData['stats']['chapterMedian'] / $srcData['stats']['chapterMedian'];

	$tarData['stats']['chapterAverageSource'] = $srcData['stats']['chapterAverage'];
	$tarData['stats']['chapterAverageVar'] = $tarData['stats']['chapterAverage'] / $srcData['stats']['chapterAverage'];

	$tarData['stats']['chapterHighSource'] = $srcData['stats']['chapterHigh'];
	$tarData['stats']['chapterHighVar'] = $tarData['stats']['chapterHigh'] / $srcData['stats']['chapterHigh'];

	$tarData['stats']['frameLowSource'] = $srcData['stats']['frameLow'];
	$tarData['stats']['frameLowVar'] = $tarData['stats']['frameLow'] / $srcData['stats']['frameLow'];

	$tarData['stats']['frameMedianSource'] = $srcData['stats']['frameMedian'];
	$tarData['stats']['frameMedianVar'] = $tarData['stats']['frameMedian'] / $srcData['stats']['frameMedian'];

	$tarData['stats']['frameAverageSource'] = $srcData['stats']['frameAverage'];
	$tarData['stats']['frameAverageVar'] = $tarData['stats']['frameAverage'] / $srcData['stats']['frameAverage'];

	$tarData['stats']['frameHighSource'] = $srcData['stats']['frameHigh'];
	$tarData['stats']['frameHighVar'] = $tarData['stats']['frameHigh'] / $srcData['stats']['frameHigh'];

	$tarData['stats']['needsAttention'] = false;

	$median = ($tarData['stats']['frameMedianVar'] + $tarData['stats']['chapterMedianVar']) / 2;

	foreach($tarData['chapters'] as $chapterIndex=>&$chapter){
		$chapter['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['stats']['count'];
		$chapter['stats']['countVar'] = $chapter['stats']['count'] / $srcData['chapters'][$chapterIndex]['stats']['count'];

		$chapter['stats']['frameLowSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameLow'];
		$chapter['stats']['frameLowVar'] = $chapter['stats']['frameLow'] / $srcData['chapters'][$chapterIndex]['stats']['frameLow'];

		$chapter['stats']['frameMedianSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameMedian'];
		$chapter['stats']['frameMedianVar'] = $chapter['stats']['frameMedian'] / $srcData['chapters'][$chapterIndex]['stats']['frameMedian'];

		$chapter['stats']['frameAverageSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameAverage'];
		$chapter['stats']['frameAverageVar'] = $chapter['stats']['frameAverage'] / $srcData['chapters'][$chapterIndex]['stats']['frameAverage'];

		$chapter['stats']['frameHighSource'] = $srcData['chapters'][$chapterIndex]['stats']['frameHigh'];
		$chapter['stats']['frameHighVar'] = $chapter['stats']['frameHigh'] / $srcData['chapters'][$chapterIndex]['stats']['frameHigh'];

		$chapter['stats']['needsAttention'] = false;

		foreach($chapter['frames'] as $frameIndex=>&$frame){
			$frame['stats']['countSource'] = $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];
			$frame['stats']['countVar'] = $frame['stats']['count'] / $srcData['chapters'][$chapterIndex]['frames'][$frameIndex]['stats']['count'];
			$frame['stats']['needsAttention'] = false;

			if( $frame['stats']['countVar'] < ($median - .2) || $frame['stats']['countVar'] > ($median + .2) ){
				$tarData['stats']['needsAttention'] = true;
				$chapter['stats']['needsAttention'] = true;
				$frame['stats']['needsAttention'] = true;
			}
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

		.clear {
			clear: left;
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
<div>
	<?php foreach($data as $language=>$info):
		if($language == $source)
			continue;

		$median = ($info['stats']['frameMedianVar'] + $info['stats']['chapterMedianVar']) / 2;
		?>
		<div class="language" id="<?php echo $language?>">
			<div class="heading<?php echo ($info['stats']['needsAttention']?' warning':'')?>">Target: <?php echo $catalog[$language]['string'].' ('.$language.')'?></div>
			<div class="item clear break">Char Count: <?php echo number_format($info['stats']['count'])?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['countSource'])?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['countVar'] * 100, 2)?>%</div>
			<div class="item clear break">Chapter Low: <?php echo number_format($info['stats']['chapterLow'])?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['chapterLowSource'])?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['chapterLowVar'] * 100, 2)?>%</div>
			<div class="item clear">Chapter High: <?php echo number_format($info['stats']['chapterHigh'])?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['chapterHighSource'])?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['chapterHighVar'] * 100, 2)?>%</div>
			<div class="item clear">Chapter Median: <?php echo number_format($info['stats']['chapterMedian'], 2)?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['chapterMedianSource'], 2)?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['chapterMedianVar'] * 100, 2)?>%</div>
			<div class="item clear">Chapter Average: <?php echo number_format($info['stats']['chapterAverage'], 2)?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['chapterAverageSource'], 2)?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['chapterAverageVar'] * 100, 2)?>%</div>
			<div class="item clear break">Frame Low: <?php echo number_format($info['stats']['frameLow'])?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['frameLowSource'])?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['frameLowVar'] * 100, 2)?>%</div>
			<div class="item clear">Frame High: <?php echo number_format($info['stats']['frameHigh'])?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['frameHighSource'])?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['frameHighVar'] * 100, 2)?>%</div>
			<div class="item clear">Frame Median: <?php echo number_format($info['stats']['frameMedian'], 2)?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['frameMedianSource'], 2)?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['frameMedianVar'] * 100, 2)?>%</div>
			<div class="item clear">Frame Average: <?php echo number_format($info['stats']['frameAverage'], 2)?></div>
			<div class="item">Source: <?php echo number_format($info['stats']['frameAverageSource'], 2)?></div>
			<div class="item">Variation: <?php echo number_format($info['stats']['frameAverageVar'] * 100, 2)?>%</div>
			<div class="item toggle-container<?php echo ($info['stats']['needsAttention']?' warning':'')?>"><a href="#" class="toggle">▼</a></div>

			<div class="chapters" id="<?php echo $language?>-chapters" style="display:none">
				<?php foreach($info['chapters'] as $chapterIndex=>$chapter):?>
					<div class="chapter" id="<?php echo $language?>-chapter-<?php echo $chapter['number']?>">
						<div class="heading<?php echo ($chapter['stats']['needsAttention']?' warning':'')?>">Chapter: <?php echo $chapter['title']?></div>
						<div class="item clear">Char Count: <?php echo number_format($chapter['stats']['count'])?></div>
						<div class="item">Source: <?php echo number_format($chapter['stats']['countSource'])?></div>
						<div class="item">Variation: <?php echo number_format($chapter['stats']['countVar'] * 100, 2)?>%</div>
						<div class="item clear break">Frame Low: <?php echo number_format($chapter['stats']['frameLow'])?></div>
						<div class="item">Source: <?php echo number_format($chapter['stats']['frameLowSource'])?></div>
						<div class="item">Variation: <?php echo number_format($chapter['stats']['frameLowVar'] * 100, 2)?>%</div>
						<div class="item clear">Frame High: <?php echo number_format($chapter['stats']['frameHigh'])?></div>
						<div class="item">Source: <?php echo number_format($chapter['stats']['frameHighSource'])?></div>
						<div class="item">Variation: <?php echo number_format($chapter['stats']['frameHighVar'] * 100, 2)?>%</div>
						<div class="item clear">Frame Median: <?php echo number_format($chapter['stats']['frameMedian'], 2)?></div>
						<div class="item">Source: <?php echo number_Format($chapter['stats']['frameMedianSource'], 2)?></div>
						<div class="item">Variation: <?php echo number_format($chapter['stats']['frameMedianVar'] * 100, 2)?>%</div>
						<div class="item clear">Frame Average: <?php echo number_format($chapter['stats']['frameAverage'], 2)?></div>
						<div class="item">Source: <?php echo number_format($chapter['stats']['frameAverageSource'], 2)?></div>
						<div class="item">Variation: <?php echo number_format($chapter['stats']['frameAverageVar'] * 100, 2)?>%</div>
						<div class="item toggle-container<?php echo ($chapter['stats']['needsAttention']?' warning':'')?>"><a href="#" class="toggle">▼</a></div>

						<div class="frames" id="<?php echo $language?>-<?php echo $chapter['number']?>-frames" style="display:none">
							<?php foreach($chapter['frames'] as $frameIndex=>$frame):?>
								<div class="frame" id="<?php echo $language?>-frame-<?php echo $frame['id']?>">
									<div class="heading<?php echo ($frame['stats']['needsAttention']?' warning':'')?>">Frame: <?php echo $frame['id']?></div>
									<div class="item clear">Char Count: <?php echo number_format($frame['stats']['count'])?></div>
									<div class="item">Source: <?php echo number_format($frame['stats']['countSource'])?></div>
									<div class="item">Variation: <?php echo number_format($frame['stats']['countVar'] * 100, 2)?>%</div>
									<div class="item toggle-container<?php echo ($frame['stats']['needsAttention']?' warning':'')?>"><a href="#" class="toggle">▼</a></div>

									<div class="sentences clear" id="<?php echo $language?>-frame-<?php echo $frame['id']?>-sentences" style="display:none;">
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

</body>
</html>

<script type="text/javascript">
	$(document).ready(function(){
		$('.language > .toggle-container > a.toggle').click(function(){
			$(this).parent().siblings('.chapters').toggle();
			if($(this).html() == "▲")
				$(this).html("▼");
			else
				$(this).html("▲");
			return false;
		});

		$('.chapter > .toggle-container > a.toggle').click(function(){
			$(this).parent().siblings('.frames').toggle();
			if($(this).html() == "▲")
				$(this).html("▼");
			else
				$(this).html("▲");
			return false;
		});

		$('.frame > .toggle-container > a.toggle').click(function(){
			$(this).parent().siblings('.sentences').toggle();
			if($(this).html() == "▲")
				$(this).html("▼");
			else
				$(this).html("▲");
			return false;
		});
	});
</script>
<?php endif;?>
