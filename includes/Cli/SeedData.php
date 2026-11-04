<?php
/**
 * Fixture content for `wp publiclms seed`.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Realistic courses, instructors and learners for the internal alpha.
 *
 * Copy is written in English and not translated: it is sample content, like
 * the WordPress importer's theme unit test data.
 */
final class SeedData {

	/**
	 * Instructors: login => display name.
	 *
	 * Logins are role-based; display names come from several scripts, so
	 * certificates, dashboards and reports show more than Latin text.
	 *
	 * @return array<string, string>
	 */
	public static function instructors(): array {
		return array(
			'instructor'        => 'Amara Okafor',
			'instructor.wang'   => '王芳',
			'instructor.ivanov' => 'Дмитрий Иванов',
		);
	}

	/**
	 * Learners: login => display name.
	 *
	 * The first learner, `learner`, finishes a course and has its
	 * certificate (in Arabic script); the second has a hand-in waiting for a
	 * grade.
	 *
	 * @return array<string, string>
	 */
	public static function students(): array {
		return array(
			'learner'           => 'ليلى حداد',
			'learner.sharma'    => 'प्रिया शर्मा',
			'learner.fernandez' => 'Lucía Fernández',
			'learner.sato'      => '佐藤 健',
			'learner.kim'       => '김지우',
			'learner.walsh'     => 'Liam Walsh',
		);
	}

	/**
	 * Courses with their sections and lessons.
	 *
	 * Each lesson is [ title, minutes, body ].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function courses(): array {
		return array(
			array(
				'title'       => 'Bookkeeping Basics for Small Businesses',
				'instructor'  => 'instructor',
				'category'    => 'Business',
				'level'       => 'Beginner',
				'excerpt'     => 'Set up simple, reliable books for a small business and close your first month with confidence.',
				'description' => 'This course is for owners of small shops, studios and service businesses who keep their own books. You will set up a chart of accounts, record income and expenses as they happen, and reconcile with the bank every month.|By the end you will have a monthly routine that takes less than an hour and gives you numbers you can trust when you talk to your accountant or apply for a loan.',
				'sections'    => array(
					'Getting your books in order'   => array(
						array( 'Why bookkeeping matters', 8, 'Good books tell you whether the business is making money, which customers pay late and how much tax to set aside. Without them, every decision is a guess.' ),
						array( 'Choosing a chart of accounts', 12, 'A chart of accounts is the list of categories every transaction goes into. Start with fewer categories than you think you need: sales, cost of goods, rent, wages, utilities and owner drawings cover most small businesses.' ),
						array( 'Cash or accrual?', 10, 'Cash accounting records money when it moves; accrual records it when it is earned or owed. Most small businesses start on cash because it matches the bank statement, then move to accrual as they grow.' ),
					),
					'Recording income and expenses' => array(
						array( 'Keeping receipts the easy way', 9, 'Photograph each receipt the day you get it and name the file with the date, supplier and amount. A shared folder per month is enough; the habit matters more than the software.' ),
						array( 'Reconciling your bank account', 15, 'Reconciling means matching every line on the bank statement with a line in your books. Differences usually come from bank fees, duplicates or payments you forgot to record.' ),
						array( 'Your first monthly report', 14, 'A profit and loss statement for the month shows income, expenses and the difference. Compare it with the same month last year to see whether you are growing.' ),
					),
				),
			),
			array(
				'title'       => 'Introduction to Python Programming',
				'instructor'  => 'instructor.ivanov',
				'category'    => 'Technology',
				'level'       => 'Beginner',
				'excerpt'     => 'Write your first useful Python programs, from variables and decisions to functions and files.',
				'description' => 'No programming experience is needed. Each lesson introduces one idea and ends with a short exercise you can run on your own computer.|You will finish by writing a small program that reads a list of expenses from a file and prints a monthly summary.',
				'sections'    => array(
					'First steps'       => array(
						array( 'Installing Python and running your first script', 12, 'Install the current version of Python from python.org, open a terminal and type python3 --version. Then save print("Hello") in a file called hello.py and run it.' ),
						array( 'Variables, numbers and strings', 14, 'A variable is a name for a value. Python works out the type for you: whole numbers, decimals and text behave differently, so 2 + 2 is 4 but "2" + "2" is "22".' ),
						array( 'Making decisions with if', 13, 'An if statement runs code only when a condition is true. Indentation shows Python which lines belong to the condition, so be consistent with four spaces.' ),
					),
					'Working with data' => array(
						array( 'Lists and loops', 16, 'A list holds many values in order. A for loop visits each one in turn, which is how you total a column of numbers or print every name in a register.' ),
						array( 'Writing your own functions', 15, 'A function packages steps under a name so you can reuse them. Give it a clear name and one job, and return the result instead of printing it.' ),
						array( 'Reading and writing files', 18, 'Use with open(...) to read a file line by line and close it automatically. Writing works the same way with the mode set to "w" or "a".' ),
					),
				),
			),
			array(
				'title'       => 'Conversational Nepali for Travellers',
				'instructor'  => 'instructor',
				'category'    => 'Languages',
				'level'       => 'Beginner',
				'excerpt'     => 'Greet people, shop, eat and get around in Nepal with everyday Nepali phrases and good manners.',
				'description' => 'Learn the phrases travellers use most, with pronunciation tips and the cultural context that makes conversations warmer.|Every lesson includes a short dialogue to practise aloud before your trip.',
				'sections'    => array(
					'Greetings and basics' => array(
						array( 'Namaste and everyday greetings', 8, 'Namaste, said with palms together, works at any time of day. Use dai for an older man and didi for an older woman; it is friendlier than a name.' ),
						array( 'Numbers, prices and bargaining politely', 12, 'Learn ek, dui, tin up to das first; prices in markets are spoken quickly. Bargain with a smile, and stop once you have agreed a price.' ),
						array( 'Asking for directions', 10, 'Kaha chha? means where is it? Add the place first: hotel kaha chha? Expect answers that point rather than give street names.' ),
					),
					'Getting around'       => array(
						array( 'Ordering food at a bhojanalaya', 11, 'Dal bhat comes with free refills of rice and lentils; say pugyo when you have had enough. Eat with your right hand if you want to join in.' ),
						array( 'Taking buses and taxis', 9, 'Agree the taxi fare before you get in. On local buses, the conductor collects fares on the way, so keep small notes ready.' ),
						array( 'Staying with a host family', 13, 'Take off your shoes at the door, accept tea when it is offered and ask before taking photos. A small gift from home is always appreciated.' ),
					),
				),
			),
			array(
				'title'       => 'Digital Photography: Light and Composition',
				'instructor'  => 'instructor.wang',
				'category'    => 'Arts',
				'level'       => 'Beginner',
				'excerpt'     => 'Take control of your camera and learn to see light, so every photo looks the way you intended.',
				'description' => 'Works with any camera that lets you set exposure manually, including most phones in pro mode.|Each lesson ends with a short assignment to shoot the same scene two ways and compare the results.',
				'sections'    => array(
					'Understanding your camera' => array(
						array( 'Aperture, shutter speed and ISO', 15, 'These three settings decide how much light reaches the sensor. Aperture also controls depth of field, shutter speed freezes or blurs motion, and ISO trades brightness for noise.' ),
						array( 'Reading the histogram', 10, 'The histogram shows how many pixels are dark, mid-tone or bright. If it is pressed against the right edge, highlights are lost and cannot be recovered.' ),
						array( 'Focus modes that matter', 9, 'Single focus locks once, continuous focus follows a moving subject. Choose your focus point yourself instead of letting the camera guess.' ),
					),
					'Seeing the picture'        => array(
						array( 'The rule of thirds and when to break it', 11, 'Placing the subject on a third line usually feels balanced. Centre it on purpose when you want symmetry or a direct, confronting look.' ),
						array( 'Working with natural light', 14, 'Soft light from a window or an overcast sky flatters faces. Hard midday sun makes strong shadows, which can be dramatic in black and white.' ),
						array( 'Editing without overdoing it', 12, 'Adjust exposure and white balance first, then contrast. Step away for a few minutes before exporting; overcooked edits are easier to see with fresh eyes.' ),
					),
				),
			),
			array(
				'title'       => 'Teaching Online: Designing Engaging Lessons',
				'instructor'  => 'instructor.wang',
				'category'    => 'Education',
				'level'       => 'Intermediate',
				'excerpt'     => 'Plan online lessons that learners finish, with clear outcomes, short chunks and activities that check understanding.',
				'description' => 'For teachers, trainers and subject experts moving a course online for the first time.|You will redesign one of your own lessons as you go, and leave with a template you can reuse.',
				'sections'    => array(
					'Planning for learning'    => array(
						array( 'Writing clear learning outcomes', 12, 'A good outcome starts with an action a learner can show: explain, calculate, design. If you cannot imagine how to check it, rewrite it.' ),
						array( 'Chunking content into short lessons', 10, 'Online attention is shorter than in a classroom. Aim for lessons of ten to fifteen minutes, each built around one idea.' ),
						array( 'Choosing the right media', 11, 'Use video to show a process, text for ideas learners will reread, and audio for stories. Always provide captions or a transcript.' ),
					),
					'Keeping learners engaged' => array(
						array( 'Activities that check understanding', 13, 'Ask learners to apply an idea, not repeat it. A short scenario with a decision to make reveals more than a recall question.' ),
						array( 'Giving feedback that helps', 12, 'Feedback is most useful when it is specific, timely and points to the next step. Comment on the work, not the person.' ),
						array( 'Building a learning community', 10, 'Learners who know each other finish more often. Start with a low-stakes introduction activity and reply to the first posts yourself.' ),
					),
				),
			),
			array(
				'title'       => 'Personal Finance Fundamentals',
				'instructor'  => 'instructor.ivanov',
				'category'    => 'Business',
				'level'       => 'Beginner',
				'excerpt'     => 'Build a budget you can keep, protect yourself with an emergency fund and start saving for the things that matter.',
				'description' => 'Practical money skills without jargon. You will use a simple spreadsheet; no apps or accounts are required.|The course does not recommend specific products and is not financial advice.',
				'sections'    => array(
					'Know where your money goes' => array(
						array( 'Tracking spending for a month', 9, 'Write down every payment for thirty days, however small. Most people find one or two categories that are much larger than they expected.' ),
						array( 'Building a simple budget', 12, 'Start from your income, take out fixed costs, then set limits for the rest. A budget is a plan you can change, not a punishment.' ),
						array( 'An emergency fund first', 8, 'Three months of essential costs in an easy-access account turns a broken laptop or a lost job from a crisis into an inconvenience.' ),
					),
					'Planning ahead'             => array(
						array( 'Understanding interest and debt', 13, 'Interest is the price of borrowing. Pay off the most expensive debt first while keeping up minimum payments on the rest.' ),
						array( 'Saving for big goals', 10, 'Give each goal a name, a date and a monthly amount. Automatic transfers on payday make saving the default.' ),
						array( 'Getting started with investing', 15, 'Investing suits money you will not need for five years or more. Low-cost, diversified funds spread risk across many companies.' ),
					),
				),
			),
			array(
				'title'       => 'Web Accessibility Essentials',
				'instructor'  => 'instructor.ivanov',
				'category'    => 'Technology',
				'level'       => 'Intermediate',
				'excerpt'     => 'Build websites everyone can use, with structure, text alternatives, contrast and keyboard support done right.',
				'description' => 'For designers, developers and content editors. Each lesson pairs one WCAG principle with a fix you can make today.|You will audit a page of your own and leave with a prioritised list of improvements.',
				'sections'    => array(
					'Why accessibility matters' => array(
						array( 'Who we design for', 9, 'About one in six people lives with a disability, and many more have temporary or situational limits such as a broken arm or bright sunlight on a screen.' ),
						array( 'The four WCAG principles', 12, 'Content must be perceivable, operable, understandable and robust. Every success criterion in WCAG sits under one of these four.' ),
						array( 'Testing with a keyboard', 11, 'Unplug the mouse and use Tab, Shift+Tab, Enter and Space. You should always see where focus is and reach every control in a sensible order.' ),
					),
					'Building it right'         => array(
						array( 'Headings, landmarks and structure', 13, 'Screen reader users jump between headings and landmarks. Use one h1, nest headings in order and wrap the main content in a main element.' ),
						array( 'Writing useful alternative text', 10, 'Describe what the image means in context, not what it looks like pixel by pixel. Decorative images get an empty alt attribute.' ),
						array( 'Colour contrast and focus states', 12, 'Body text needs a contrast ratio of at least 4.5 to 1. Never remove the focus outline without replacing it with something equally visible.' ),
					),
				),
			),
			array(
				'title'       => 'Climate Science for Everyone',
				'instructor'  => 'instructor.wang',
				'category'    => 'Science',
				'level'       => 'Beginner',
				'excerpt'     => 'Understand how the climate works, how it is changing and what communities are doing about it.',
				'description' => 'A plain-language introduction using real data from public sources. No maths beyond reading a chart.|The final section looks at practical adaptation and everyday choices.',
				'sections'    => array(
					'How the climate works' => array(
						array( 'Weather versus climate', 8, 'Weather is what happens today; climate is the pattern over thirty years or more. A cold week does not change a warming trend.' ),
						array( 'The greenhouse effect explained', 11, 'Some gases let sunlight in but slow down heat escaping. More carbon dioxide thickens that blanket, so the surface warms.' ),
						array( 'Reading a temperature record', 10, 'Look at the long-term line, not single years. Most records show the change compared with a baseline period, called the anomaly.' ),
					),
					'Changes and choices'   => array(
						array( 'What the oceans tell us', 12, 'Oceans absorb most of the extra heat and a quarter of the carbon dioxide. Warmer water expands and raises sea levels.' ),
						array( 'Adapting communities to heat and floods', 13, 'Shade trees, early warning systems and better drainage save lives. Adaptation works best when the people affected help plan it.' ),
						array( 'Practical steps at home and at work', 9, 'Heating, travel and food make up most household emissions. Start with the change that is easiest for you to keep.' ),
					),
				),
			),
			array(
				'title'       => 'Public Speaking with Confidence',
				'instructor'  => 'instructor',
				'category'    => 'Personal Development',
				'level'       => 'Beginner',
				'excerpt'     => 'Prepare talks people remember and deliver them calmly, from a team meeting to a conference stage.',
				'description' => 'Practical techniques used by teachers, trainers and speakers, with short practice tasks you can record on your phone.|You will prepare and rehearse a five-minute talk on a topic you care about.',
				'sections'    => array(
					'Preparing your talk' => array(
						array( 'Know your audience', 9, 'Ask what your listeners already know and what they need to do after your talk. Everything you include should serve that.' ),
						array( 'Structuring a talk that sticks', 12, 'Open with a question or a story, make no more than three points, and finish by telling people exactly what to do next.' ),
						array( 'Writing speaker notes, not scripts', 8, 'A script tempts you to read. Notes with key phrases and the first sentence of each section keep you natural and on track.' ),
					),
					'On the day'          => array(
						array( 'Managing nerves', 10, 'Nerves are energy. Slow breathing, a short walk and a glass of water before you start bring your heart rate down.' ),
						array( 'Voice, pace and pauses', 11, 'Most nervous speakers rush. A two-second pause after an important point gives the audience time to think and makes you look confident.' ),
						array( 'Handling questions', 10, 'Repeat the question so everyone hears it, answer briefly, and say "I will find out" when you do not know.' ),
					),
				),
			),
			array(
				'title'       => 'Introduction to Statistics',
				'instructor'  => 'instructor.ivanov',
				'category'    => 'Science',
				'level'       => 'Intermediate',
				'excerpt'     => 'Describe data honestly and draw sound conclusions, with averages, spread, sampling and probability.',
				'description' => 'Uses everyday examples such as exam results, rainfall and shop sales. A calculator or spreadsheet is enough.|By the end you will be able to read a statistics claim in the news and judge whether it holds up.',
				'sections'    => array(
					'Describing data'     => array(
						array( 'Types of data', 9, 'Categories, rankings and measurements need different summaries. Knowing which kind you have prevents most beginner mistakes.' ),
						array( 'Mean, median and mode', 11, 'The mean is pulled by extreme values; the median is not. When incomes or house prices are reported, ask which one was used.' ),
						array( 'Measuring spread', 12, 'Two classes can have the same average mark and very different ranges. Standard deviation summarises how far values sit from the mean.' ),
					),
					'Drawing conclusions' => array(
						array( 'Samples and populations', 12, 'A sample is useful only if it represents the population. Asking your friends is convenient but rarely representative.' ),
						array( 'Probability in plain words', 13, 'Probability is a number between nought and one for how likely something is. One in five and twenty percent mean the same thing.' ),
						array( 'Correlation is not causation', 10, 'Ice cream sales and drownings both rise in summer; neither causes the other. Look for a third factor before claiming a cause.' ),
					),
				),
			),
		);
	}

	/**
	 * One quiz per course, keyed by course title: four questions in editor form
	 * (QuestionBank::sanitize()), mixing the six types.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function quizzes(): array {
		$choice = static function ( string $text, string $right, array $wrong, string $why = '' ): array {
			$options = array(
				array(
					'text'    => $right,
					'correct' => true,
				),
			);
			foreach ( $wrong as $option ) {
				$options[] = array( 'text' => $option );
			}
			return array(
				'type'        => 'single',
				'text'        => $text,
				'options'     => $options,
				'explanation' => $why,
			);
		};
		$truth  = static function ( string $text, bool $answer, string $why = '' ): array {
			return array(
				'type'        => 'true_false',
				'text'        => $text,
				'answer'      => $answer,
				'explanation' => $why,
			);
		};
		$short  = static function ( string $text, array $accepted, string $why = '' ): array {
			return array(
				'type'        => 'short_answer',
				'text'        => $text,
				'accepted'    => $accepted,
				'explanation' => $why,
			);
		};
		$blank  = static function ( string $template, string $why = '' ): array {
			return array(
				'type'        => 'fill_blank',
				'text'        => 'Fill in the missing words.',
				'template'    => $template,
				'points'      => 2,
				'explanation' => $why,
			);
		};
		$many   = static function ( string $text, array $right, array $wrong, string $why = '' ): array {
			$options = array();
			foreach ( $right as $option ) {
				$options[] = array(
					'text'    => $option,
					'correct' => true,
				);
			}
			foreach ( $wrong as $option ) {
				$options[] = array( 'text' => $option );
			}
			return array(
				'type'        => 'multiple',
				'text'        => $text,
				'options'     => $options,
				'points'      => 2,
				'explanation' => $why,
			);
		};
		$match  = static function ( string $text, array $pairs ): array {
			$list = array();
			foreach ( $pairs as $prompt => $answer ) {
				$list[] = array(
					'prompt' => $prompt,
					'match'  => $answer,
				);
			}
			return array(
				'type'   => 'matching',
				'text'   => $text,
				'pairs'  => $list,
				'points' => 2,
			);
		};

		return array(
			'Bookkeeping Basics for Small Businesses'     => array(
				$choice( 'Which method records money when it actually moves in or out of the bank?', 'Cash accounting', array( 'Accrual accounting', 'Double-entry accounting' ), 'Cash accounting matches the bank statement, which is why most small businesses start with it.' ),
				$truth( 'A chart of accounts should start with as many categories as possible.', false, 'Start with fewer categories than you think you need; you can split them later.' ),
				$many( 'Which of these are usually expenses?', array( 'Rent', 'Wages' ), array( 'Sales', 'Owner capital' ) ),
				$short( 'What do you call matching every line of the bank statement with a line in your books?', array( 'Reconciliation', 'Reconciling', 'Bank reconciliation' ) ),
			),
			'Introduction to Python Programming'          => array(
				$choice( 'What does "2" + "2" give in Python?', '"22"', array( '4', 'An error' ), 'Adding two strings joins them.' ),
				$blank( 'A {{function}} packages steps under a name, and should {{return}} its result rather than print it.' ),
				$truth( 'Python uses indentation to show which lines belong to an if statement.', true ),
				$match(
					'Match each idea to what it does.',
					array(
						'for loop' => 'Visits each item of a list in turn',
						'if'       => 'Runs code only when a condition is true',
						'list'     => 'Holds many values in order',
					)
				),
			),
			'Conversational Nepali for Travellers'        => array(
				$choice( 'What do you say when you have had enough dal bhat?', 'Pugyo', array( 'Namaste', 'Kaha chha?' ), 'Pugyo means "that is enough".' ),
				$truth( 'Namaste works at any time of day.', true ),
				$short( 'Which word do you use for an older woman, instead of her name?', array( 'Didi' ) ),
				$match(
					'Match the Nepali to its meaning.',
					array(
						'Kaha chha?' => 'Where is it?',
						'Ek'         => 'One',
						'Dai'        => 'Older brother',
					)
				),
			),
			'Digital Photography: Light and Composition'  => array(
				$many( 'Which settings decide how much light reaches the sensor?', array( 'Aperture', 'Shutter speed', 'ISO' ), array( 'White balance' ) ),
				$truth( 'Highlights lost against the right edge of the histogram can be recovered in editing.', false, 'Clipped highlights hold no detail to recover.' ),
				$choice( 'Which light flatters faces most?', 'Soft light from a window or an overcast sky', array( 'Hard midday sun', 'A bare flash pointed straight on' ) ),
				$blank( 'Placing the subject on a {{third}} line usually feels balanced; centre it for {{symmetry|a symmetrical look}}.' ),
			),
			'Teaching Online: Designing Engaging Lessons' => array(
				$truth( 'Every lesson should start from what learners will be able to do at the end.', true ),
				$choice( 'What keeps learners engaged in a long video lesson?', 'Short segments with a task between them', array( 'One uninterrupted recording', 'More slides per minute' ) ),
				$short( 'What do we call a short check of understanding during a lesson?', array( 'Formative assessment', 'Check for understanding', 'Quiz' ) ),
				$many( 'Which make online lessons more accessible?', array( 'Captions on videos', 'Clear headings' ), array( 'Text inside images only' ) ),
			),
			'Personal Finance Fundamentals'               => array(
				$choice( 'What should you build before investing?', 'An emergency fund', array( 'A second credit card', 'A larger car loan' ) ),
				$truth( 'Tracking spending for a month shows where your money really goes.', true ),
				$blank( 'Pay yourself {{first}}: move savings out on {{payday}}.' ),
				$match(
					'Match each term to its meaning.',
					array(
						'Budget'   => 'A plan for income and spending',
						'Interest' => 'The cost of borrowing money',
						'Asset'    => 'Something you own that has value',
					)
				),
			),
			'Web Accessibility Essentials'                => array(
				$choice( 'What is the minimum contrast for normal text under WCAG AA?', '4.5:1', array( '3:1', '2:1' ) ),
				$truth( 'A button must be reachable and usable with the keyboard alone.', true ),
				$short( 'Which HTML attribute gives an image its text alternative?', array( 'alt' ) ),
				$many( 'Which help screen reader users find their way?', array( 'Headings in order', 'Landmarks such as main and nav' ), array( 'Text set in light grey' ) ),
			),
			'Climate Science for Everyone'                => array(
				$choice( 'Which gas from burning fossil fuels drives most of the recent warming?', 'Carbon dioxide', array( 'Oxygen', 'Nitrogen' ) ),
				$truth( 'Weather is the long-term average of climate.', false, 'It is the other way round: climate is the long-term average of weather.' ),
				$blank( 'The {{greenhouse}} effect keeps Earth warm enough for life.' ),
				$short( 'What do we call cutting emissions to limit warming?', array( 'Mitigation', 'Climate mitigation' ) ),
			),
			'Public Speaking with Confidence'             => array(
				$truth( 'Knowing your first two minutes by heart helps with nerves.', true ),
				$choice( 'What should a talk end with?', 'One clear thing to remember or do', array( 'A list of every point again', 'An apology for running over' ) ),
				$many( 'Which help an audience follow you?', array( 'Signposting what comes next', 'Pausing after key points' ), array( 'Reading every slide aloud' ) ),
				$short( 'What do you call the short practice talk you give before the real one?', array( 'Rehearsal', 'Dry run', 'Run-through' ) ),
			),
			'Introduction to Statistics'                  => array(
				$choice( 'Which average is least affected by a few very large values?', 'The median', array( 'The mean', 'The total' ) ),
				$truth( 'Correlation shows that one thing causes another.', false, 'Correlation is not causation.' ),
				$blank( 'The {{mean}} adds all values and divides by how many there are.' ),
				$match(
					'Match each term to its meaning.',
					array(
						'Sample'     => 'The people or things you measured',
						'Population' => 'Everyone the question is about',
						'Outlier'    => 'A value far from the rest',
					)
				),
			),
		);
	}

	/**
	 * One assignment brief per course, keyed by course title: [ title, brief ].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function assignments(): array {
		return array(
			'Bookkeeping Basics for Small Businesses'     => array( 'Close your first month', 'Record one month of income and expenses, reconcile the bank account and upload your profit and loss statement as a PDF or spreadsheet.' ),
			'Introduction to Python Programming'          => array( 'Your expense summary program', 'Write a program that reads a file of expenses and prints a total per category. Upload the .py file as a .txt or zip, with a sample input file.' ),
			'Conversational Nepali for Travellers'        => array( 'Record a market conversation', 'Write a short dialogue at a market stall: greet the seller, ask a price, agree on it and say thank you. Type it below or upload a recording.' ),
			'Digital Photography: Light and Composition'  => array( 'One scene, two ways', 'Photograph the same scene twice: once following the rule of thirds and once breaking it on purpose. Upload both photos and say which works better.' ),
			'Teaching Online: Designing Engaging Lessons' => array( 'Plan a twenty-minute lesson', 'Write the outcome, three segments and one check for understanding for a lesson you will teach. Upload it as a PDF or document.' ),
			'Personal Finance Fundamentals'               => array( 'Your one-month budget', 'Track your spending for a week, then write a budget for next month with a savings line. Upload a spreadsheet or type it below.' ),
			'Web Accessibility Essentials'                => array( 'Audit one page', 'Test one web page with the keyboard only and a contrast checker. List three problems and how to fix each.' ),
			'Climate Science for Everyone'                => array( 'Your household footprint', 'Estimate your household\'s yearly emissions with any calculator and name two changes you could make this year.' ),
			'Public Speaking with Confidence'             => array( 'A two-minute talk', 'Prepare a two-minute talk on something you know well. Upload your outline, and a recording if you made one.' ),
			'Introduction to Statistics'                  => array( 'Describe a data set', 'Pick any small data set, give its mean, median and range, and say what they tell you. Upload a spreadsheet or type your answer.' ),
		);
	}
}
