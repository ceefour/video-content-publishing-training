## Installation

Requires https://github.com/PHP-FFMpeg/PHP-FFMpeg library

The recommended way to install PHP-FFMpeg is through Composer.

    composer require -v php-ffmpeg/php-ffmpeg

## MySQL Database

    create table video
    (
        id varchar(255) not null
            primary key,
        progress int default '0' not null,
        progresstext text null,
        progresssub int default '0' not null,
        playcount int default '0' not null
    );

## Encoder daemon

* encoderd.php

## Web app pages

* index.php
* uploadform.php
* show.php

## JSON API

* progress_json.php
* played_json.php

## Example

Examples:

* http://localhost/vcp101-2/
* http://localhost/vcp101-2/show.php?id=DPR_RI_KOMISI_V_BERI_CATATAN_TERKAIT_MUDIK_2017
* http://localhost/vcp101-2/progress_json.php?id=DPR_RI_KOMISI_V_BERI_CATATAN_TERKAIT_MUDIK_2017
