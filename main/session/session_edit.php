<?php
/* For licensing terms, see /license.txt */

/**
 * Sessions edition script
 * @package chamilo.admin
 */

$cidReset = true;
require_once '../inc/global.inc.php';

// setting the section (for the tabs)
$this_section = SECTION_PLATFORM_ADMIN;

$formSent = 0;

// Database Table Definitions
$tbl_user = Database::get_main_table(TABLE_MAIN_USER);
$tbl_session = Database::get_main_table(TABLE_MAIN_SESSION);

$id = intval($_GET['id']);

SessionManager::protectSession($id);

$infos = SessionManager::fetch($id);

$id_coach = $infos['id_coach'];
$tool_name = get_lang('EditSession');

//$interbreadcrumb[] = array('url' => 'index.php',"name" => get_lang('PlatformAdmin'));
$interbreadcrumb[] = array('url' => "session_list.php","name" => get_lang('SessionList'));
$interbreadcrumb[] = array('url' => "resume_session.php?id_session=".$id,"name" => get_lang('SessionOverview'));

list($year_start, $month_start, $day_start) = explode('-', $infos['access_start_date']);
list($year_end, $month_end, $day_end) = explode('-', $infos['access_end_date']);

if (isset($_POST['formSent']) && $_POST['formSent']) {
	$formSent = 1;
}

$order_clause = 'ORDER BY ';
$order_clause .= api_sort_by_first_name() ? 'firstname, lastname, username' : 'lastname, firstname, username';

$sql = "SELECT user_id,lastname,firstname,username
        FROM $tbl_user
        WHERE status='1'".$order_clause;

if (api_is_multiple_url_enabled()) {
	$table_access_url_rel_user= Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
	$access_url_id = api_get_current_access_url_id();
	if ($access_url_id != -1) {
		$sql = "SELECT DISTINCT u.user_id,lastname,firstname,username
		        FROM $tbl_user u
                INNER JOIN $table_access_url_rel_user url_rel_user ON (url_rel_user.user_id = u.user_id)
			    WHERE status='1' AND access_url_id = '$access_url_id' $order_clause";
	}
}

$result = Database::query($sql);
$coaches = Database::store_result($result);
$thisYear = date('Y');

$coachesOption = array(
    '' => '----- ' . get_lang('None') . ' -----'
);

foreach ($coaches as $coach) {
    $personName = api_get_person_name($coach['firstname'], $coach['lastname']);
    $coachesOption[$coach['user_id']] = "$personName ({$coach['username']})";
}

$categoriesList = SessionManager::get_all_session_category();

$categoriesOption = array(
    '0' => get_lang('None')
);

if ($categoriesList != false) {
    foreach ($categoriesList as $categoryItem) {
        $categoriesOption[$categoryItem['id']] = $categoryItem['name'];
    }
}

$formAction = api_get_self() . '?';
$formAction .= http_build_query(array(
    'page' => Security::remove_XSS($_GET['page']),
    'id' => $id
));

$form = new FormValidator('edit_session', 'post', $formAction);

$form->addElement('header', $tool_name);

$form->addElement('text', 'name', get_lang('SessionName'), array(
    'class' => 'span4',
    'maxlength' => 50,
    'value' => $formSent ? api_htmlentities($name,ENT_QUOTES, $charset) : ''
));
$form->addRule('name', get_lang('ThisFieldIsRequired'), 'required');
$form->addRule('name', get_lang('SessionNameAlreadyExists'), 'callback', 'check_session_name');

if (!api_is_platform_admin() && api_is_teacher()) {
    $userInfo = api_get_user_info();
    $coachesOption = [api_get_user_id() => $userInfo['complete_name']];
}


$form->addElement('select', 'id_coach', get_lang('CoachName'), $coachesOption, array(
    'id' => 'coach_username',
    'class' => 'chzn-select',
    'style' => 'width:370px;',
    'title' => get_lang('Choose')
));
$form->addRule('id_coach', get_lang('ThisFieldIsRequired'), 'required');

$form->addButtonAdvancedSettings('advanced_params');
$form->addElement('html', '<div id="advanced_params_options" style="display:none">');


$form->addSelect('session_category', get_lang('SessionCategory'), $categoriesOption, array(
    'id' => 'session_category',
    'class' => 'chzn-select',
    'style' => 'width:370px;'
));

$form->addHtmlEditor(
    'description',
    get_lang('Description'),
    false,
    false,
    array(
        'ToolbarSet' => 'Minimal'
    )
);

$chkDescriptionAttributes = array();

if (!empty($infos['show_description'])) {
    $chkDescriptionAttributes['checked'] = '';
}

$form->addElement('checkbox', 'show_description', null, get_lang('ShowDescription'), $chkDescriptionAttributes);

$form->addElement('date_time_picker', 'display_start_date', array(get_lang('SessionDisplayStartDate'), get_lang('SessionDisplayStartDateComment')));
$form->addElement('date_time_picker', 'display_end_date', array(get_lang('SessionDisplayEndDate'), get_lang('SessionDisplayEndDateComment')));
$form->addElement('date_time_picker', 'access_start_date', array(get_lang('SessionStartDate'), get_lang('SessionStartDateComment')));
$form->addElement('date_time_picker', 'access_end_date', array(get_lang('SessionEndDate'), get_lang('SessionEndDateComment')));
$form->addElement('date_time_picker', 'coach_access_start_date', array(get_lang('SessionCoachStartDate'), get_lang('SessionCoachStartDateComment')));
$form->addElement('date_time_picker', 'coach_access_end_date', array(get_lang('SessionCoachEndDate'), get_lang('SessionCoachEndDateComment')));

$visibilityGroup = array();
$visibilityGroup[] = $form->createElement(
    'select',
    'session_visibility',
    null,
    array(
        SESSION_VISIBLE_READ_ONLY => get_lang('SessionReadOnly'),
        SESSION_VISIBLE => get_lang('SessionAccessible'),
        SESSION_INVISIBLE => api_ucfirst(get_lang('SessionNotAccessible')),
    ),
    array(
        'style' => 'width:250px;',
    )
);

$form->addGroup($visibilityGroup, 'visibility_group', get_lang('SessionVisibility'), null, false);

$form->addElement('html','</div>');

$duration = empty($infos['duration']) ? null : $infos['duration'];

$form->addElement(
    'text',
    'duration',
    array(
        get_lang('SessionDurationTitle'),
        get_lang('SessionDurationDescription')
    ),
    array(
        'maxlength' => 50
    )
);

//Extra fields
$extra_field = new ExtraField('session');
$extra = $extra_field->addElements($form, $id);

$form->addElement('html','</div>');

$htmlHeadXtra[] ='
<script>

$(function() {
    '.$extra['jquery_ready_content'].'
});
</script>';

$form->addButtonUpdate(get_lang('ModifyThisSession'));

$formDefaults = array(
    'id_coach' => $infos['id_coach'],
    'session_category' => $infos['session_category_id'],
    'session_visibility' => $infos['visibility'],
    'description' => $infos['description']
);

if ($formSent) {
    $formDefaults['name'] = api_htmlentities($name, ENT_QUOTES, $charset);
    $formDefaults['display_start_date'] = $displayStartDate;
    $formDefaults['display_end_date'] = $displayEndDate;
    $formDefaults['access_start_date'] = $startDate;
    $formDefaults['access_end_date'] = $endDate;
    $formDefaults['coach_access_start_date'] = $coachAccessStartDate;
    $formDefaults['coach_access_end_date'] = $coachAccessEndDate;
    $formDefaults['duration'] = Security::remove_XSS($duration);
} else {
    $formDefaults['name'] = Security::remove_XSS($infos['name']);
    $formDefaults['display_start_date'] = $infos['display_start_date'];
    $formDefaults['display_end_date'] = $infos['display_end_date'];
    $formDefaults['access_start_date'] = $infos['access_start_date'];
    $formDefaults['access_end_date'] = $infos['access_end_date'];
    $formDefaults['coach_access_start_date'] = $infos['coach_access_start_date'];
    $formDefaults['coach_access_end_date'] = $infos['coach_access_end_date'];
    $formDefaults['duration'] = $duration;
}

$form->setDefaults($formDefaults);

if ($form->validate()) {
    $params = $form->getSubmitValues();

    $name = $params['name'];
    $displayStartDate = $params['display_start_date'];
    $displayEndDate = $params['display_end_date'];
    $startDate = $params['access_start_date'];
    $endDate = $params['access_end_date'];
    $coachAccessStartDate = $params['coach_access_start_date'];
    $coachAccessEndDate = $params['coach_access_end_date'];
    $id_coach = $params['id_coach'];
    $id_session_category = $params['session_category'];
    $id_visibility = $params['session_visibility'];
    $duration = isset($params['duration']) ? $params['duration'] : null;
    $description = $params['description'];
    $showDescription = isset($params['show_description']) ? 1: 0;

    $noLimit = false;
    $startLimit = true;
    $endLimit = true;
    if (empty($startDate) || $startDate == '0000-00-00 00:00:00') {
        $startLimit = false;
    }
    if (empty($endDate) || $endDate == '0000-00-00 00:00:00') {
        $endLimit = false;
    }
    if (!$startLimit && !$endLimit) {
        $noLimit = true;
    }

    $extraFields = array();

    foreach ($params as $key => $value) {
        if (strpos($key, 'extra_') === 0) {
            $extraFields[$key] = $value;
        }
    }

    $return = SessionManager::edit_session(
        $id,
        $name,
        $startDate,
        $endDate,
        $coachAccessStartDate,
        $coachAccessEndDate,
        $noLimit,
        $id_coach,
        $id_session_category,
        $id_visibility,
        $startLimit,
        $endLimit,
        $description,
        $showDescription,
        $duration,
        $displayStartDate,
        $displayEndDate,
        $extraFields
    );

    if ($return == strval(intval($return))) {
        header('Location: resume_session.php?id_session=' . $return);
        exit();
    }
}

// display the header
Display::display_header($tool_name);

if (!empty($return)) {
    Display::display_error_message($return,false);
}

$form->display();
?>

<script type="text/javascript">
function setDisable(select) {
	document.forms['edit_session'].elements['session_visibility'].disabled = (select.checked) ? true : false;
	document.forms['edit_session'].elements['session_visibility'].selectedIndex = 0;

    /*
    document.forms['edit_session'].elements['start_limit'].disabled = (select.checked) ? true : false;
    document.forms['edit_session'].elements['start_limit'].checked = false;
    document.forms['edit_session'].elements['end_limit'].disabled = (select.checked) ? true : false;
    document.forms['edit_session'].elements['end_limit'].checked = false;

    var end_div = document.getElementById('end_date');
    end_div.style.display = 'none';

    var start_div = document.getElementById('start_date');
    start_div.style.display = 'none';
    */
}

function disable_endtime(select) {
    var end_div = document.getElementById('end_date');
    if (end_div.style.display == 'none')
        end_div.style.display = 'block';
     else
        end_div.style.display = 'none';
    emptyDuration();
}

function disable_starttime(select) {
    var start_div = document.getElementById('start_date');
    if (start_div.style.display == 'none')
        start_div.style.display = 'block';
     else
        start_div.style.display = 'none';
    emptyDuration();
}

function emptyDuration() {
    if ($('#duration').val()) {
        $('#duration').val('');
    }
}

$(document).on('ready', function (){
    $('#show-options').on('click', function (e) {
        e.preventDefault();
        var display = $('#options').css('display');
        display === 'block' ? $('#options').slideUp() : $('#options').slideDown() ;
    });
});

</script>
<?php
Display::display_footer();
