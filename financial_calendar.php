<?php

session_start();
require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| DATABASE COLUMN HELPERS
|--------------------------------------------------------------------------
*/

function getTableColumns($conn, $table)
{
    $columns = [];

    $result = $conn->query(
        "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "`"
    );

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $columns[] = $row["Field"];
        }

        $result->free();
    }

    return $columns;
}


function findColumn($columns, $possibleNames)
{
    foreach ($possibleNames as $name) {

        if (in_array($name, $columns, true)) {
            return $name;
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| TABLE / COLUMN DETECTION
|--------------------------------------------------------------------------
*/

$table = "financial_calendar";

$columns = getTableColumns(
    $conn,
    $table
);

$idColumn = findColumn(
    $columns,
    ["id"]
);

$userColumn = findColumn(
    $columns,
    ["user_id"]
);

$titleColumn = findColumn(
    $columns,
    [
        "title",
        "event_title",
        "name",
        "event_name"
    ]
);

$dateColumn = findColumn(
    $columns,
    [
        "event_date",
        "date",
        "start_date"
    ]
);

$typeColumn = findColumn(
    $columns,
    [
        "event_type",
        "type"
    ]
);

$amountColumn = findColumn(
    $columns,
    [
        "amount",
        "planned_amount",
        "event_amount"
    ]
);

$descriptionColumn = findColumn(
    $columns,
    [
        "description",
        "notes",
        "details"
    ]
);

$createdColumn = findColumn(
    $columns,
    ["created_at"]
);


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

$message = "";
$messageType = "";


/*
|--------------------------------------------------------------------------
| DELETE EVENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_event"])
) {

    $event_id =
        (int)($_POST["event_id"] ?? 0);


    if (
        $event_id <= 0
        || !$idColumn
        || !$userColumn
    ) {

        $message =
            "Unable to delete this event.";

        $messageType = "error";

    } else {

        $sql = "
            DELETE FROM `$table`
            WHERE `$idColumn` = ?
              AND `$userColumn` = ?
        ";

        $stmt = $conn->prepare($sql);


        if ($stmt) {

            $stmt->bind_param(
                "ii",
                $event_id,
                $user_id
            );


            if ($stmt->execute()) {

                if ($stmt->affected_rows > 0) {

                    header(
                        "Location: financial_calendar.php?deleted=1"
                    );

                    exit;

                } else {

                    $message =
                        "Event not found.";

                    $messageType = "error";
                }

            } else {

                $message =
                    "Unable to delete the event.";

                $messageType = "error";
            }


            $stmt->close();

        } else {

            $message =
                "Unable to prepare delete request.";

            $messageType = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| DELETE SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET["deleted"])) {

    $message =
        "Financial event deleted successfully.";

    $messageType = "success";
}


/*
|--------------------------------------------------------------------------
| ADD FINANCIAL EVENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["add_event"])
) {

    $event_title =
        trim(
            $_POST["event_title"] ?? ""
        );

    $event_date =
        trim(
            $_POST["event_date"] ?? ""
        );

    $event_type =
        trim(
            $_POST["event_type"] ?? ""
        );

    $amount =
        trim(
            $_POST["amount"] ?? ""
        );

    $description =
        trim(
            $_POST["description"] ?? ""
        );


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        $event_title === ""
        || $event_date === ""
    ) {

        $message =
            "Please enter an event title and date.";

        $messageType = "error";

    } elseif (
        !$userColumn
        || !$titleColumn
        || !$dateColumn
    ) {

        $message =
            "Financial Calendar database columns are incomplete.";

        $messageType = "error";

    } elseif (
        !preg_match(
            "/^\d{4}-\d{2}-\d{2}$/",
            $event_date
        )
    ) {

        $message =
            "Please enter a valid event date.";

        $messageType = "error";

    } elseif (
        $amount !== ""
        && !is_numeric($amount)
    ) {

        $message =
            "Please enter a valid amount.";

        $messageType = "error";

    } elseif (
        $amount !== ""
        && (float)$amount < 0
    ) {

        $message =
            "Amount cannot be negative.";

        $messageType = "error";

    } else {


        /*
        |--------------------------------------------------------------------------
        | BUILD DYNAMIC INSERT
        |--------------------------------------------------------------------------
        */

        $insertColumns = [];
        $insertValues = [];
        $types = "";
        $params = [];


        /*
        | USER
        */

        $insertColumns[] =
            "`$userColumn`";

        $insertValues[] =
            "?";

        $types .= "i";

        $params[] =
            $user_id;


        /*
        | TITLE
        */

        $insertColumns[] =
            "`$titleColumn`";

        $insertValues[] =
            "?";

        $types .= "s";

        $params[] =
            $event_title;


        /*
        | DATE
        */

        $insertColumns[] =
            "`$dateColumn`";

        $insertValues[] =
            "?";

        $types .= "s";

        $params[] =
            $event_date;


        /*
        | TYPE
        */

        if ($typeColumn) {

            $insertColumns[] =
                "`$typeColumn`";

            $insertValues[] =
                "?";

            $types .= "s";

            $params[] =
                $event_type;
        }


        /*
        | AMOUNT
        */

        if ($amountColumn) {

            $insertColumns[] =
                "`$amountColumn`";

            $insertValues[] =
                "?";

            $types .= "d";

            $params[] =
                (
                    $amount === ""
                    ? 0
                    : (float)$amount
                );
        }


        /*
        | DESCRIPTION
        */

        if ($descriptionColumn) {

            $insertColumns[] =
                "`$descriptionColumn`";

            $insertValues[] =
                "?";

            $types .= "s";

            $params[] =
                $description;
        }


        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        $sql =
            "INSERT INTO `$table` ("
            . implode(
                ", ",
                $insertColumns
            )
            . ") VALUES ("
            . implode(
                ", ",
                $insertValues
            )
            . ")";


        $stmt =
            $conn->prepare($sql);


        if (!$stmt) {

            $message =
                "Unable to prepare the event. Please check the database.";

            $messageType = "error";

        } else {


            /*
            |--------------------------------------------------------------------------
            | BIND PARAMETERS
            |--------------------------------------------------------------------------
            */

            $bindParams = [];

            $bindParams[] =
                $types;


            foreach ($params as $key => $value) {
                $bindParams[] =
                    &$params[$key];
            }


            call_user_func_array(
                [$stmt, "bind_param"],
                $bindParams
            );


            if ($stmt->execute()) {

                header(
                    "Location: financial_calendar.php?success=1"
                );

                exit;

            } else {

                $message =
                    "Unable to add the event: "
                    . $stmt->error;

                $messageType = "error";
            }


            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (isset($_GET["success"])) {

    $message =
        "Financial event added successfully.";

    $messageType = "success";
}


/*
|--------------------------------------------------------------------------
| LOAD EVENTS
|--------------------------------------------------------------------------
*/

$events = [];


if (
    $userColumn
    && $dateColumn
) {


    $selectParts = [];


    /*
    | ID
    */

    if ($idColumn) {

        $selectParts[] =
            "`$idColumn` AS event_id";

    } else {

        $selectParts[] =
            "0 AS event_id";
    }


    /*
    | DATE
    */

    $selectParts[] =
        "`$dateColumn` AS event_date";


    /*
    | TITLE
    */

    if ($titleColumn) {

        $selectParts[] =
            "`$titleColumn` AS event_title";

    } else {

        $selectParts[] =
            "'' AS event_title";
    }


    /*
    | TYPE
    */

    if ($typeColumn) {

        $selectParts[] =
            "`$typeColumn` AS event_type";

    } else {

        $selectParts[] =
            "'' AS event_type";
    }


    /*
    | AMOUNT
    */

    if ($amountColumn) {

        $selectParts[] =
            "`$amountColumn` AS amount";

    } else {

        $selectParts[] =
            "0 AS amount";
    }


    /*
    | DESCRIPTION
    */

    if ($descriptionColumn) {

        $selectParts[] =
            "`$descriptionColumn` AS description";

    } else {

        $selectParts[] =
            "'' AS description";
    }


    /*
    | CREATED DATE
    */

    if ($createdColumn) {

        $selectParts[] =
            "`$createdColumn` AS created_at";

    } else {

        $selectParts[] =
            "NULL AS created_at";
    }


    $sql =
        "SELECT "
        . implode(
            ", ",
            $selectParts
        )
        . " FROM `$table`
           WHERE `$userColumn` = ?
           ORDER BY `$dateColumn` ASC";


    $stmt =
        $conn->prepare($sql);


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $user_id
        );

        $stmt->execute();

        $result =
            $stmt->get_result();


        while (
            $row =
            $result->fetch_assoc()
        ) {

            $events[] =
                $row;
        }


        $stmt->close();
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH / FILTER
|--------------------------------------------------------------------------
*/

$search =
    trim(
        $_GET["search"] ?? ""
    );

$typeFilter =
    trim(
        $_GET["type"] ?? ""
    );

$statusFilter =
    trim(
        $_GET["status"] ?? ""
    );


$today =
    date("Y-m-d");


$filteredEvents = [];


foreach ($events as $event) {


    $eventTitle =
        strtolower(
            $event["event_title"] ?? ""
        );


    $eventDescription =
        strtolower(
            $event["description"] ?? ""
        );


    $eventType =
        $event["event_type"] ?? "";


    $eventDate =
        $event["event_date"] ?? "";


    /*
    |--------------------------------------------------------------------------
    | SEARCH
    |--------------------------------------------------------------------------
    */

    if ($search !== "") {

        $searchLower =
            strtolower($search);


        if (
            strpos(
                $eventTitle,
                $searchLower
            ) === false
            &&
            strpos(
                $eventDescription,
                $searchLower
            ) === false
            &&
            strpos(
                strtolower($eventType),
                $searchLower
            ) === false
        ) {

            continue;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | TYPE FILTER
    |--------------------------------------------------------------------------
    */

    if (
        $typeFilter !== ""
        && $eventType !== $typeFilter
    ) {

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | STATUS FILTER
    |--------------------------------------------------------------------------
    */

    if (
        $statusFilter === "upcoming"
        && $eventDate < $today
    ) {

        continue;
    }


    if (
        $statusFilter === "past"
        && $eventDate >= $today
    ) {

        continue;
    }


    $filteredEvents[] =
        $event;
}


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$currentMonth =
    date("Y-m");

$eventsThisMonth = 0;
$upcomingEvents = 0;
$pastEvents = 0;
$plannedAmount = 0;

$nextEvent = null;


foreach ($events as $event) {

    $eventDate =
        $event["event_date"] ?? "";

    $eventAmount =
        (float)(
            $event["amount"] ?? 0
        );


    /*
    |--------------------------------------------------------------------------
    | THIS MONTH
    |--------------------------------------------------------------------------
    */

    if (
        substr(
            $eventDate,
            0,
            7
        ) === $currentMonth
    ) {

        $eventsThisMonth++;
    }


    /*
    |--------------------------------------------------------------------------
    | UPCOMING / PLANNED
    |--------------------------------------------------------------------------
    */

    if (
        $eventDate >= $today
    ) {

        $upcomingEvents++;

        $plannedAmount +=
            $eventAmount;


        if (
            $nextEvent === null
            || $eventDate
                < $nextEvent["event_date"]
        ) {

            $nextEvent =
                $event;
        }

    } else {

        $pastEvents++;
    }
}


/*
|--------------------------------------------------------------------------
| MONTH CALENDAR
|--------------------------------------------------------------------------
*/

$calendarYear =
    isset($_GET["year"])
    ? (int)$_GET["year"]
    : (int)date("Y");


$calendarMonth =
    isset($_GET["month"])
    ? (int)$_GET["month"]
    : (int)date("m");


if ($calendarMonth < 1) {

    $calendarMonth = 12;
    $calendarYear--;

}


if ($calendarMonth > 12) {

    $calendarMonth = 1;
    $calendarYear++;
}


$calendarStart =
    mktime(
        0,
        0,
        0,
        $calendarMonth,
        1,
        $calendarYear
    );


$daysInMonth =
    date(
        "t",
        $calendarStart
    );


$firstWeekday =
    (int)date(
        "w",
        $calendarStart
    );


$calendarMonthName =
    date(
        "F Y",
        $calendarStart
    );


/*
|--------------------------------------------------------------------------
| EVENTS BY DATE
|--------------------------------------------------------------------------
*/

$eventsByDate = [];


foreach ($events as $event) {

    $date =
        $event["event_date"] ?? "";


    if ($date !== "") {

        if (
            !isset(
                $eventsByDate[$date]
            )
        ) {

            $eventsByDate[$date] = [];
        }


        $eventsByDate[$date][] =
            $event;
    }
}


/*
|--------------------------------------------------------------------------
| EVENT TYPES
|--------------------------------------------------------------------------
*/

$eventTypes = [];


foreach ($events as $event) {

    $type =
        trim(
            $event["event_type"] ?? ""
        );


    if (
        $type !== ""
        && !in_array(
            $type,
            $eventTypes,
            true
        )
    ) {

        $eventTypes[] =
            $type;
    }
}


sort($eventTypes);


/*
|--------------------------------------------------------------------------
| MONEY HELPER
|--------------------------------------------------------------------------
*/

function formatMoney($amount)
{
    return "₹" . number_format(
        (float)$amount,
        2
    );
}


/*
|--------------------------------------------------------------------------
| EVENT STATUS
|--------------------------------------------------------------------------
*/

function getEventStatus($date)
{
    $today =
        date("Y-m-d");


    if ($date < $today) {

        return "Past";
    }


    if ($date === $today) {

        return "Today";
    }


    return "Upcoming";
}


function getEventStatusClass($date)
{
    $status =
        getEventStatus($date);


    if ($status === "Past") {
        return "past";
    }


    if ($status === "Today") {
        return "today";
    }


    return "upcoming";
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Financial Calendar - Expense Tracker
    </title>

    <script>
        (function() {
            var theme = localStorage.getItem('expenseTrackerTheme') || 'system';
            var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="stylesheet" href="dark_theme.css">

<style>

/* =========================================================
   RESET
========================================================= */

* {

    margin: 0;

    padding: 0;

    box-sizing: border-box;
}


/* =========================================================
   BODY
========================================================= */

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f4f7fb;

    color: #172033;
}


/* =========================================================
   MAIN
========================================================= */

.main-content {

    margin-left: 250px;

    width:
        calc(100% - 250px);

    min-height: 100vh;

    padding: 40px;
}


.calendar-page {

    max-width: 1400px;

    margin: 0 auto;
}


/* =========================================================
   HEADER
========================================================= */

.page-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap: 20px;

    margin-bottom: 30px;
}


.page-header h1 {

    font-size: 34px;

    font-weight: 800;

    color: #162033;

    margin-bottom: 6px;
}


.page-header p {

    color: #718096;

    font-size: 15px;
}


.date-badge {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    padding:
        12px 18px;

    border-radius: 10px;

    color: #667085;

    font-size: 14px;

    font-weight: 600;

    box-shadow:
        0 5px 18px
        rgba(24,39,75,.05);
}


/* =========================================================
   ALERTS
========================================================= */

.alert {

    padding:
        14px 18px;

    border-radius: 10px;

    margin-bottom: 22px;

    font-size: 14px;

    font-weight: 600;
}


.alert-success {

    background: #eaf8ef;

    border:
        1px solid #bde5ca;

    color: #19703a;
}


.alert-error {

    background: #fff0f0;

    border:
        1px solid #f0c0c0;

    color: #b42318;
}


/* =========================================================
   STATISTICS
========================================================= */

.stats-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 20px;

    margin-bottom: 24px;
}


.stat-card {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    border-radius: 18px;

    padding: 24px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);

    transition: .2s ease;
}


.stat-card:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 12px 30px
        rgba(24,39,75,.09);
}


.stat-label {

    font-size: 12px;

    font-weight: 700;

    color: #718096;

    text-transform:
        uppercase;

    letter-spacing: .5px;

    margin-bottom: 10px;
}


.stat-value {

    font-size: 29px;

    font-weight: 800;

    color: #172033;
}


.stat-blue {
    color: #159fe3;
}


.stat-green {
    color: #159447;
}


.stat-red {
    color: #e04444;
}


.stat-purple {
    color: #7957d5;
}


/* =========================================================
   TWO COLUMN AREA
========================================================= */

.calendar-layout {

    display: grid;

    grid-template-columns:
        1fr 1.15fr;

    gap: 24px;

    margin-bottom: 24px;
}


/* =========================================================
   CARD
========================================================= */

.card {

    background: #ffffff;

    border:
        1px solid #e6ebf2;

    border-radius: 18px;

    padding: 26px;

    box-shadow:
        0 8px 25px
        rgba(24,39,75,.06);
}


.card-title {

    font-size: 20px;

    font-weight: 800;

    color: #172033;

    margin-bottom: 5px;
}


.card-subtitle {

    color: #718096;

    font-size: 13px;

    line-height: 1.5;

    margin-bottom: 24px;
}


/* =========================================================
   FORM
========================================================= */

.form-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 18px;
}


.form-group {

    display: flex;

    flex-direction: column;
}


.form-group.full {

    grid-column:
        1 / -1;
}


.form-group label {

    font-size: 14px;

    font-weight: 700;

    color: #344054;

    margin-bottom: 8px;
}


.form-group input,
.form-group select,
.form-group textarea {

    width: 100%;

    border:
        1px solid #d7dee8;

    border-radius: 10px;

    padding:
        12px 14px;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 14px;

    outline: none;

    background: #ffffff;

    color: #172033;

    transition: .2s;
}


.form-group input,
.form-group select {

    height: 46px;
}


.form-group textarea {

    min-height: 105px;

    resize: vertical;
}


.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {

    border-color: #18aef1;

    box-shadow:
        0 0 0 3px
        rgba(24,174,241,.12);
}


.add-event-btn {

    width: 100%;

    border: none;

    border-radius: 10px;

    padding:
        13px 18px;

    background:
        linear-gradient(
            135deg,
            #16b5f4,
            #168ce8
        );

    color: #ffffff;

    font-size: 14px;

    font-weight: 800;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    cursor: pointer;

    transition: .2s;

    margin-top: 5px;
}


.add-event-btn:hover {

    opacity: .92;

    transform:
        translateY(-1px);
}


/* =========================================================
   NEXT EVENT
========================================================= */

.next-event {

    margin-top: 22px;

    padding:
        16px;

    background:
        linear-gradient(
            135deg,
            #f0f9ff,
            #f8fbff
        );

    border:
        1px solid #d9edf9;

    border-radius: 13px;
}


.next-event-label {

    color: #718096;

    font-size: 11px;

    font-weight: 800;

    text-transform:
        uppercase;

    letter-spacing: .5px;

    margin-bottom: 7px;
}


.next-event-title {

    font-size: 15px;

    font-weight: 800;

    color: #172033;
}


.next-event-date {

    color: #168ce8;

    font-size: 12px;

    font-weight: 700;

    margin-top: 5px;
}


/* =========================================================
   FILTERS
========================================================= */

.filter-bar {

    display: grid;

    grid-template-columns:
        1.5fr 1fr 1fr auto;

    gap: 10px;

    margin-bottom: 20px;
}


.filter-input,
.filter-select {

    height: 42px;

    border:
        1px solid #d7dee8;

    border-radius: 9px;

    padding:
        9px 12px;

    background: #ffffff;

    color: #344054;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 13px;

    outline: none;
}


.filter-input:focus,
.filter-select:focus {

    border-color: #18aef1;

    box-shadow:
        0 0 0 3px
        rgba(24,174,241,.10);
}


.filter-btn {

    height: 42px;

    padding:
        0 18px;

    border: none;

    border-radius: 9px;

    background: #172033;

    color: #ffffff;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 13px;

    font-weight: 700;

    cursor: pointer;
}


.filter-btn:hover {

    background: #25344c;
}


.clear-filter {

    display: inline-flex;

    align-items:
        center;

    justify-content:
        center;

    height: 42px;

    padding:
        0 15px;

    border:
        1px solid #d7dee8;

    border-radius: 9px;

    background: #ffffff;

    color: #667085;

    text-decoration: none;

    font-size: 13px;

    font-weight: 700;
}


/* =========================================================
   EVENT LIST
========================================================= */

.event-list {

    display: flex;

    flex-direction: column;

    gap: 12px;

    max-height: 650px;

    overflow-y: auto;

    padding-right: 3px;
}


.event-item {

    border:
        1px solid #e6ebf2;

    border-radius: 14px;

    padding: 17px;

    background: #fbfcfe;

    transition: .2s ease;
}


.event-item:hover {

    background: #ffffff;

    box-shadow:
        0 6px 18px
        rgba(24,39,75,.07);

    transform:
        translateY(-1px);
}


.event-top {

    display: flex;

    justify-content:
        space-between;

    align-items:
        flex-start;

    gap: 12px;
}


.event-title {

    font-size: 15px;

    font-weight: 800;

    color: #172033;
}


.event-date {

    color: #168ce8;

    font-size: 12px;

    font-weight: 700;

    margin-top: 5px;
}


.event-type {

    display: inline-flex;

    align-items:
        center;

    background: #eaf6ff;

    color: #168ce8;

    border-radius: 20px;

    padding:
        5px 10px;

    font-size: 10px;

    font-weight: 800;

    white-space: nowrap;
}


.event-amount {

    font-size: 16px;

    font-weight: 800;

    color: #e04444;

    margin-top: 11px;
}


.event-description {

    color: #718096;

    font-size: 12px;

    line-height: 1.5;

    margin-top: 7px;
}


.event-bottom {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    gap: 10px;

    margin-top: 13px;

    padding-top: 11px;

    border-top:
        1px solid #e9edf2;
}


.status-badge {

    display: inline-flex;

    padding:
        5px 9px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 800;
}


.status-badge.upcoming {

    background: #eaf8ef;

    color: #159447;
}


.status-badge.today {

    background: #fff5df;

    color: #b66a00;
}


.status-badge.past {

    background: #f0f2f5;

    color: #7b8491;
}


.delete-form {

    margin: 0;
}


.delete-btn {

    border: none;

    background: transparent;

    color: #e04444;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size: 11px;

    font-weight: 700;

    cursor: pointer;

    padding: 4px 6px;
}


.delete-btn:hover {

    text-decoration: underline;
}


/* =========================================================
   CALENDAR
========================================================= */

.calendar-card {

    margin-bottom: 24px;
}


.calendar-header {

    display: flex;

    justify-content:
        space-between;

    align-items:
        center;

    margin-bottom: 20px;
}


.calendar-title {

    font-size: 20px;

    font-weight: 800;

    color: #172033;
}


.calendar-navigation {

    display: flex;

    align-items:
        center;

    gap: 7px;
}


.calendar-navigation a {

    width: 34px;

    height: 34px;

    display: flex;

    align-items:
        center;

    justify-content:
        center;

    border:
        1px solid #dfe5ed;

    border-radius: 8px;

    background: #ffffff;

    color: #344054;

    text-decoration: none;

    font-weight: 800;

    transition: .2s;
}


.calendar-navigation a:hover {

    background: #f2f8fc;

    border-color: #b8e3f7;

    color: #168ce8;
}


.calendar-month {

    min-width: 120px;

    text-align: center;

    font-size: 14px;

    font-weight: 800;

    color: #344054;
}


.calendar-weekdays {

    display: grid;

    grid-template-columns:
        repeat(7, 1fr);

    gap: 7px;

    margin-bottom: 7px;
}


.calendar-weekday {

    text-align: center;

    padding: 8px 3px;

    font-size: 11px;

    color: #8994a5;

    font-weight: 800;

    text-transform:
        uppercase;
}


.calendar-grid {

    display: grid;

    grid-template-columns:
        repeat(7, 1fr);

    gap: 7px;
}


.calendar-day {

    min-height: 105px;

    background: #fbfcfe;

    border:
        1px solid #e6ebf2;

    border-radius: 10px;

    padding: 9px;

    position: relative;

    overflow: hidden;
}


.calendar-day.empty-day {

    background: #f8fafc;

    border-color:
        #f0f2f5;
}


.calendar-day.today-day {

    border:
        2px solid #20b9f5;

    background: #f3fbff;
}


.day-number {

    font-size: 12px;

    font-weight: 800;

    color: #667085;

    margin-bottom: 7px;
}


.today-day .day-number {

    color: #168ce8;
}


.calendar-event {

    display: block;

    background: #eaf6ff;

    border-left:
        3px solid #168ce8;

    border-radius: 5px;

    padding:
        5px 6px;

    margin-bottom: 5px;

    color: #24526e;

    text-decoration: none;

    font-size: 10px;

    line-height: 1.25;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;
}


.calendar-event:hover {

    background: #dff2fd;
}


.calendar-event.bill {

    background: #fff1f1;

    border-left-color: #e04444;

    color: #963737;
}


.calendar-event.payment {

    background: #eaf8ef;

    border-left-color: #159447;

    color: #23653b;
}


.calendar-event.income {

    background: #f0ecff;

    border-left-color: #7957d5;

    color: #5941a0;
}


.calendar-event.emi {

    background: #fff5df;

    border-left-color: #d89017;

    color: #8a5c0b;
}


.calendar-more {

    color: #168ce8;

    font-size: 10px;

    font-weight: 800;

    margin-top: 4px;
}


/* =========================================================
   EMPTY
========================================================= */

.empty-events {

    text-align: center;

    padding:
        45px 20px;

    color: #718096;
}


.empty-icon {

    font-size: 42px;

    margin-bottom: 12px;
}


.empty-events strong {

    display: block;

    color: #344054;

    font-size: 16px;

    margin-bottom: 5px;
}


.empty-events p {

    font-size: 13px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .stats-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .calendar-layout {

        grid-template-columns: 1fr;
    }

    .filter-bar {

        grid-template-columns:
            1fr 1fr;
    }
}


@media (max-width: 850px) {

    .main-content {

        margin-left: 210px;

        width:
            calc(100% - 210px);

        padding: 25px;
    }

    .calendar-day {

        min-height: 85px;
    }
}


@media (max-width: 650px) {

    .main-content {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .page-header {

        display: block;
    }

    .date-badge {

        display: inline-block;

        margin-top: 15px;
    }

    .stats-grid {

        grid-template-columns: 1fr;
    }

    .form-grid {

        grid-template-columns: 1fr;
    }

    .form-group.full {

        grid-column: auto;
    }

    .filter-bar {

        grid-template-columns: 1fr;
    }

    .calendar-grid {

        gap: 3px;
    }

    .calendar-weekdays {

        gap: 3px;
    }

    .calendar-day {

        min-height: 70px;

        padding: 5px;

        border-radius: 6px;
    }

    .calendar-event {

        font-size: 8px;

        padding: 4px;
    }

    .day-number {

        font-size: 10px;
    }

    .calendar-header {

        align-items:
            flex-start;

        gap: 12px;
    }

    .calendar-month {

        min-width: 90px;
    }
}


@media (max-width: 450px) {

    .main-content {

        padding: 15px;
    }

    .page-header h1 {

        font-size: 28px;
    }

    .card {

        padding: 18px;
    }

    .calendar-day {

        min-height: 58px;
    }

    .calendar-event {

        border-left-width: 2px;

        padding: 3px;

        font-size: 7px;
    }

    .calendar-weekday {

        font-size: 8px;
    }
}

</style>

</head>


<body>
<script>
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
</script>


<div class="app-layout">


    <!-- =====================================================
         SHARED DASHBOARD SIDEBAR
    ====================================================== -->

    <?php include "sidebar.php"; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <div class="calendar-page">


            <!-- =================================================
                 HEADER
            ================================================== -->

            <div class="page-header">

                <div>

                    <h1>
                        Financial Calendar
                    </h1>

                    <p>
                        Plan bills, payments and important financial dates.
                    </p>

                </div>


                <div class="date-badge">

                    📅
                    <?php
                    echo date("d M Y");
                    ?>

                </div>

            </div>


            <!-- =================================================
                 ALERT
            ================================================== -->

            <?php if ($message !== ""): ?>

                <div
                    class="
                        alert
                        <?php
                        echo $messageType === "success"
                            ? "alert-success"
                            : "alert-error";
                        ?>
                    "
                >

                    <?php
                    echo htmlspecialchars(
                        $message
                    );
                    ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 STATISTICS
            ================================================== -->

            <div class="stats-grid">


                <div class="stat-card">

                    <div class="stat-label">
                        Events This Month
                    </div>

                    <div class="stat-value stat-blue">

                        <?php
                        echo $eventsThisMonth;
                        ?>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-label">
                        Upcoming Events
                    </div>

                    <div class="stat-value stat-green">

                        <?php
                        echo $upcomingEvents;
                        ?>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-label">
                        Planned Amount
                    </div>

                    <div class="stat-value stat-red">

                        <?php
                        echo formatMoney(
                            $plannedAmount
                        );
                        ?>

                    </div>

                </div>


                <div class="stat-card">

                    <div class="stat-label">
                        Past Events
                    </div>

                    <div class="stat-value stat-purple">

                        <?php
                        echo $pastEvents;
                        ?>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 ADD EVENT + UPCOMING EVENTS
            ================================================== -->

            <div class="calendar-layout">


                <!-- =================================================
                     ADD EVENT
                ================================================== -->

                <div class="card">


                    <div class="card-title">
                        Add Financial Event
                    </div>

                    <div class="card-subtitle">

                        Record bills, payments, subscriptions,
                        EMIs, income dates and other important
                        financial events.

                    </div>


                    <form method="POST">


                        <div class="form-grid">


                            <!-- TITLE -->

                            <div class="form-group full">

                                <label>
                                    Event Title
                                </label>

                                <input
                                    type="text"
                                    name="event_title"
                                    placeholder="e.g. Electricity Bill"
                                    maxlength="150"
                                    required
                                >

                            </div>


                            <!-- DATE -->

                            <div class="form-group">

                                <label>
                                    Event Date
                                </label>

                                <input
                                    type="date"
                                    name="event_date"
                                    min="2000-01-01"
                                    required
                                >

                            </div>


                            <!-- TYPE -->

                            <div class="form-group">

                                <label>
                                    Event Type
                                </label>

                                <select
                                    name="event_type"
                                >

                                    <option value="">
                                        Select type
                                    </option>

                                    <option value="Bill">
                                        Bill
                                    </option>

                                    <option value="Payment">
                                        Payment
                                    </option>

                                    <option value="Income">
                                        Income
                                    </option>

                                    <option value="Subscription">
                                        Subscription
                                    </option>

                                    <option value="EMI">
                                        EMI
                                    </option>

                                    <option value="Other">
                                        Other
                                    </option>

                                </select>

                            </div>


                            <!-- AMOUNT -->

                            <div class="form-group full">

                                <label>
                                    Amount
                                </label>

                                <input
                                    type="number"
                                    name="amount"
                                    step="0.01"
                                    min="0"
                                    placeholder="e.g. 1500"
                                >

                            </div>


                            <!-- DESCRIPTION -->

                            <div class="form-group full">

                                <label>
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    maxlength="1000"
                                    placeholder="Add optional notes..."
                                ></textarea>

                            </div>


                            <!-- SUBMIT -->

                            <div class="form-group full">

                                <button
                                    type="submit"
                                    name="add_event"
                                    class="add-event-btn"
                                >

                                    + Add Financial Event

                                </button>

                            </div>


                        </div>


                    </form>


                    <!-- NEXT EVENT -->

                    <?php if ($nextEvent !== null): ?>

                        <div class="next-event">


                            <div class="next-event-label">
                                Next Financial Event
                            </div>


                            <div class="next-event-title">

                                <?php
                                echo htmlspecialchars(
                                    $nextEvent["event_title"]
                                    ?? "Financial Event"
                                );
                                ?>

                            </div>


                            <div class="next-event-date">

                                📅

                                <?php

                                echo date(
                                    "d M Y",
                                    strtotime(
                                        $nextEvent["event_date"]
                                    )
                                );

                                ?>

                                <?php if (
                                    (float)(
                                        $nextEvent["amount"]
                                        ?? 0
                                    ) > 0
                                ): ?>

                                    ·

                                    <?php
                                    echo formatMoney(
                                        $nextEvent["amount"]
                                    );
                                    ?>

                                <?php endif; ?>


                            </div>


                        </div>

                    <?php endif; ?>


                </div>


                <!-- =================================================
                     EVENT LIST
                ================================================== -->

                <div class="card">


                    <div class="card-title">
                        Financial Events
                    </div>

                    <div class="card-subtitle">

                        Search and manage your scheduled financial events.

                    </div>


                    <!-- FILTERS -->

                    <form
                        method="GET"
                        class="filter-bar"
                    >


                        <input
                            type="text"
                            name="search"
                            class="filter-input"
                            placeholder="Search events..."
                            value="<?php
                            echo htmlspecialchars(
                                $search
                            );
                            ?>"
                        >


                        <select
                            name="type"
                            class="filter-select"
                        >

                            <option value="">
                                All Types
                            </option>


                            <?php foreach (
                                $eventTypes
                                as $type
                            ): ?>

                                <option
                                    value="<?php
                                    echo htmlspecialchars(
                                        $type
                                    );
                                    ?>"
                                    <?php
                                    echo $typeFilter === $type
                                        ? "selected"
                                        : "";
                                    ?>
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $type
                                    );
                                    ?>

                                </option>

                            <?php endforeach; ?>

                        </select>


                        <select
                            name="status"
                            class="filter-select"
                        >

                            <option value="">
                                All Events
                            </option>

                            <option
                                value="upcoming"
                                <?php
                                echo $statusFilter === "upcoming"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Upcoming
                            </option>

                            <option
                                value="past"
                                <?php
                                echo $statusFilter === "past"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Past
                            </option>

                        </select>


                        <button
                            type="submit"
                            class="filter-btn"
                        >
                            Filter
                        </button>


                    </form>


                    <?php if (
                        $search !== ""
                        || $typeFilter !== ""
                        || $statusFilter !== ""
                    ): ?>

                        <div
                            style="
                                margin-bottom:15px;
                                font-size:12px;
                                color:#718096;
                            "
                        >

                            Showing
                            <strong>
                                <?php
                                echo count(
                                    $filteredEvents
                                );
                                ?>
                            </strong>
                            matching events.

                            <a
                                href="financial_calendar.php"
                                style="
                                    color:#168ce8;
                                    text-decoration:none;
                                    font-weight:700;
                                    margin-left:5px;
                                "
                            >
                                Clear filters
                            </a>

                        </div>

                    <?php endif; ?>


                    <?php if (
                        empty($filteredEvents)
                    ): ?>


                        <div class="empty-events">

                            <div class="empty-icon">
                                📅
                            </div>

                            <strong>
                                No financial events found
                            </strong>

                            <p>
                                Add an event or change your filters.
                            </p>

                        </div>


                    <?php else: ?>


                        <div class="event-list">


                            <?php foreach (
                                $filteredEvents
                                as $event
                            ): ?>


                                <?php

                                $eventDate =
                                    $event["event_date"]
                                    ?? "";

                                $eventStatus =
                                    getEventStatus(
                                        $eventDate
                                    );

                                $statusClass =
                                    getEventStatusClass(
                                        $eventDate
                                    );

                                $eventAmount =
                                    (float)(
                                        $event["amount"]
                                        ?? 0
                                    );

                                ?>


                                <div class="event-item">


                                    <div class="event-top">


                                        <div>

                                            <div class="event-title">

                                                <?php
                                                echo htmlspecialchars(
                                                    $event["event_title"]
                                                    ?? "Financial Event"
                                                );
                                                ?>

                                            </div>


                                            <div class="event-date">

                                                📅

                                                <?php

                                                if (
                                                    $eventDate !== ""
                                                ) {

                                                    echo date(
                                                        "d M Y",
                                                        strtotime(
                                                            $eventDate
                                                        )
                                                    );
                                                }

                                                ?>

                                            </div>

                                        </div>


                                        <?php if (
                                            !empty(
                                                $event["event_type"]
                                            )
                                        ): ?>

                                            <span class="event-type">

                                                <?php
                                                echo htmlspecialchars(
                                                    $event["event_type"]
                                                );
                                                ?>

                                            </span>

                                        <?php endif; ?>


                                    </div>


                                    <?php if (
                                        $eventAmount > 0
                                    ): ?>

                                        <div class="event-amount">

                                            <?php
                                            echo formatMoney(
                                                $eventAmount
                                            );
                                            ?>

                                        </div>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $event["description"]
                                        )
                                    ): ?>

                                        <div class="event-description">

                                            <?php
                                            echo nl2br(
                                                htmlspecialchars(
                                                    $event["description"]
                                                )
                                            );
                                            ?>

                                        </div>

                                    <?php endif; ?>


                                    <div class="event-bottom">


                                        <span
                                            class="
                                                status-badge
                                                <?php
                                                echo $statusClass;
                                                ?>
                                            "
                                        >

                                            <?php
                                            echo $eventStatus;
                                            ?>

                                        </span>


                                        <?php if (
                                            (int)(
                                                $event["event_id"]
                                                ?? 0
                                            ) > 0
                                        ): ?>


                                            <form
                                                method="POST"
                                                class="delete-form"
                                                onsubmit="
                                                    return confirm(
                                                        'Delete this financial event?'
                                                    );
                                                "
                                            >

                                                <input
                                                    type="hidden"
                                                    name="event_id"
                                                    value="<?php
                                                    echo (int)(
                                                        $event[
                                                            "event_id"
                                                        ]
                                                    );
                                                    ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="delete_event"
                                                    class="delete-btn"
                                                >

                                                    Delete

                                                </button>

                                            </form>


                                        <?php endif; ?>


                                    </div>


                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php endif; ?>


                </div>


            </div>


            <!-- =================================================
                 MONTHLY CALENDAR
            ================================================== -->

            <div class="card calendar-card">


                <div class="calendar-header">


                    <div class="calendar-title">

                        Calendar

                    </div>


                    <div class="calendar-navigation">


                        <a
                            href="?year=<?php
                            echo $calendarMonth === 1
                                ? $calendarYear - 1
                                : $calendarYear;
                            ?>&month=<?php
                            echo $calendarMonth === 1
                                ? 12
                                : $calendarMonth - 1;
                            ?>"
                            title="Previous month"
                        >
                            ‹
                        </a>


                        <div class="calendar-month">

                            <?php
                            echo htmlspecialchars(
                                $calendarMonthName
                            );
                            ?>

                        </div>


                        <a
                            href="?year=<?php
                            echo $calendarMonth === 12
                                ? $calendarYear + 1
                                : $calendarYear;
                            ?>&month=<?php
                            echo $calendarMonth === 12
                                ? 1
                                : $calendarMonth + 1;
                            ?>"
                            title="Next month"
                        >
                            ›
                        </a>


                    </div>


                </div>


                <!-- WEEKDAYS -->

                <div class="calendar-weekdays">

                    <div class="calendar-weekday">
                        Sun
                    </div>

                    <div class="calendar-weekday">
                        Mon
                    </div>

                    <div class="calendar-weekday">
                        Tue
                    </div>

                    <div class="calendar-weekday">
                        Wed
                    </div>

                    <div class="calendar-weekday">
                        Thu
                    </div>

                    <div class="calendar-weekday">
                        Fri
                    </div>

                    <div class="calendar-weekday">
                        Sat
                    </div>

                </div>


                <!-- CALENDAR -->

                <div class="calendar-grid">


                    <?php for (
                        $blank = 0;
                        $blank < $firstWeekday;
                        $blank++
                    ): ?>

                        <div
                            class="
                                calendar-day
                                empty-day
                            "
                        ></div>

                    <?php endfor; ?>


                    <?php for (
                        $day = 1;
                        $day <= $daysInMonth;
                        $day++
                    ): ?>


                        <?php

                        $dateString =
                            sprintf(
                                "%04d-%02d-%02d",
                                $calendarYear,
                                $calendarMonth,
                                $day
                            );


                        $isToday =
                            $dateString === $today;


                        $dayEvents =
                            $eventsByDate[
                                $dateString
                            ] ?? [];

                        ?>


                        <div
                            class="
                                calendar-day
                                <?php
                                echo $isToday
                                    ? "today-day"
                                    : "";
                                ?>
                            "
                        >


                            <div class="day-number">

                                <?php
                                echo $day;
                                ?>

                            </div>


                            <?php

                            $displayCount = 0;


                            foreach (
                                $dayEvents
                                as $calendarEvent
                            ):

                                if (
                                    $displayCount >= 3
                                ) {
                                    break;
                                }


                                $calendarType =
                                    strtolower(
                                        trim(
                                            $calendarEvent[
                                                "event_type"
                                            ] ?? ""
                                        )
                                    );


                                $calendarTypeClass = "";


                                if (
                                    strpos(
                                        $calendarType,
                                        "bill"
                                    ) !== false
                                ) {

                                    $calendarTypeClass =
                                        "bill";

                                } elseif (
                                    strpos(
                                        $calendarType,
                                        "payment"
                                    ) !== false
                                ) {

                                    $calendarTypeClass =
                                        "payment";

                                } elseif (
                                    strpos(
                                        $calendarType,
                                        "income"
                                    ) !== false
                                ) {

                                    $calendarTypeClass =
                                        "income";

                                } elseif (
                                    strpos(
                                        $calendarType,
                                        "emi"
                                    ) !== false
                                ) {

                                    $calendarTypeClass =
                                        "emi";
                                }

                            ?>


                                <div
                                    class="
                                        calendar-event
                                        <?php
                                        echo $calendarTypeClass;
                                        ?>
                                    "
                                    title="<?php
                                    echo htmlspecialchars(
                                        $calendarEvent[
                                            "event_title"
                                        ] ?? ""
                                    );
                                    ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $calendarEvent[
                                            "event_title"
                                        ] ?? "Event"
                                    );
                                    ?>

                                </div>


                            <?php

                                $displayCount++;

                            endforeach;

                            ?>


                            <?php if (
                                count($dayEvents)
                                > 3
                            ): ?>

                                <div class="calendar-more">

                                    +
                                    <?php
                                    echo count(
                                        $dayEvents
                                    ) - 3;
                                    ?>
                                    more

                                </div>

                            <?php endif; ?>


                        </div>


                    <?php endfor; ?>


                </div>


            </div>


        </div>


    </main>


</div>


</body>

</html>