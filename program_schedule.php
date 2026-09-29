<?php
/**
 * 프로그램 › 프로그램일정: 산림치유·유아숲·숲해설 일정을 일간·주간·월간으로 (일정표와 같은 화면, 분류만 다름)
 *   program_schedule.php?view=day|week|month&date=2026-09-29
 */
require __DIR__ . '/app/bootstrap.php';

$CAL = 'program';
require __DIR__ . '/schedule.php';
