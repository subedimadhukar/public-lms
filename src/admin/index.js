/**
 * Public LMS admin app.
 *
 * One React app on WordPress components and DataViews (ADR-007, ADR-033).
 * PHP prints an empty root on each screen and the boot data in
 * window.publiclmsAdmin; this file picks the screen and renders it.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { getQueryArg } from '@wordpress/url';

import '../store/courses';
import '../store/students';
import { boot } from './boot';
import { Notices } from './components/notices';
import CourseBuilder from './courses/course-builder';
import CourseList from './courses/course-list';
import GradebookScreen from './grading/gradebook';
import CouponsScreen from './orders/coupons';
import OrdersScreen from './orders/orders';
import PaymentsScreen from './orders/payments';
import SubmissionsScreen from './grading/submissions';
import QuestionBankScreen from './quizzes/question-bank';
import QuizEditor from './quizzes/quiz-editor';
import SettingsScreen from './settings/settings-screen';
import StudentList from './students/student-list';

import './style.scss';

function App() {
	const courseId =
		Number( getQueryArg( window.location.href, 'course' ) ) || 0;
	const quizId = Number( getQueryArg( window.location.href, 'quiz' ) ) || 0;
	let screen;
	if ( boot.screen === 'students' ) {
		screen = <StudentList />;
	} else if ( boot.screen === 'settings' ) {
		screen = <SettingsScreen />;
	} else if ( boot.screen === 'questions' ) {
		screen = <QuestionBankScreen />;
	} else if ( boot.screen === 'submissions' ) {
		screen = <SubmissionsScreen />;
	} else if ( boot.screen === 'gradebook' ) {
		screen = <GradebookScreen />;
	} else if ( boot.screen === 'orders' ) {
		screen = <OrdersScreen />;
	} else if ( boot.screen === 'coupons' ) {
		screen = <CouponsScreen />;
	} else if ( boot.screen === 'payments' ) {
		screen = <PaymentsScreen />;
	} else if ( courseId > 0 && quizId > 0 ) {
		screen = <QuizEditor courseId={ courseId } quizId={ quizId } />;
	} else if ( courseId > 0 ) {
		screen = <CourseBuilder courseId={ courseId } />;
	} else {
		screen = <CourseList />;
	}
	return (
		<>
			{ screen }
			<Notices />
		</>
	);
}

domReady( () => {
	const root = document.getElementById( 'publiclms-admin-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
