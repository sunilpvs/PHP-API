<?php
    require 'GraphAutoMailer.php';
    $mailer = new AutoMail();

$keyValueData = [
    "Message" => "Your company vendor registration process initated.",
    "Vendor Name" => "<Vendor Name>",
    "Contact Person" => "IT",
    "Your EMail" => "<Email>",
    "Link" => "<vendor login URL>", 
    "User Name" => "<Email ID>",
    "Password" => "Temp Password"
];

$mailer->sendInfoEmail(
    to: ['sunil.pvs@pvs-consultancy.com','ramalakshmi@pvs-consultancy.com'],
    subject: 'Automated Graph Email with Attachments',
    greetings: 'Hello User',
    name: 'Sunil',
    keyValueArray: $keyValueData,
    cc: ['team.pvs@pvs-consultancy.com', 'bhaskar.teja@pvs-consultancy.com'],
    bcc: ['team.pvs@pvs-consultancy.com'],
    attachments: ['C:\\Allbackups\\scripts\\Task_Scheduler.txt']
);

?>