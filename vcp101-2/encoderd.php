<?php
require_once 'vendor/autoload.php';

function encode($file, $processedFolder, $mysql) {
    $path_parts = pathinfo($file);
    $incomingName = $path_parts['filename'];
    $targetFolder = $processedFolder . '/' . $path_parts['filename'];
    mkdir($targetFolder, 0755);
    print("Processing $file into $targetFolder ...\n");

    $videoId = $path_parts['filename'];
    print("Deleting database row for $videoId ...\n");
    $handle = $mysql->prepare('DELETE FROM video WHERE id=?');
    $handle->bindValue(1, $videoId);
    $handle->execute();

    $handle = $mysql->prepare('INSERT INTO video (id) VALUES (?)');
    $handle->bindValue(1, $videoId);
    $handle->execute();

    $ffmpeg = FFMpeg\FFMpeg::create();

    $handle = $mysql->prepare('UPDATE video SET progresstext=? WHERE id=?');
    $handle->bindValue(1, 'Extract thumbnail');
    $handle->bindValue(2, $videoId);
    $handle->execute();
    $thumbnailFile = $targetFolder . '/thumbnail.jpg';
    print("Extract thumbnail from $file ...\n");
    $video = $ffmpeg->open($file);
    $video
        ->filters()
        ->resize(new FFMpeg\Coordinate\Dimension(426, 480))
        ->synchronize();
    $video
        ->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(10))
        ->save($thumbnailFile);
    // add artwork (usable for MP3)
    $video->filters()->addMetadata(["artwork" => $thumbnailFile]);
        
    // https://github.com/PHP-FFMpeg/PHP-FFMpeg/issues/367
    print("Creating HLS from $file ...\n");
    $hls240file = $targetFolder . '/240p.m3u8';
    $hls480file = $targetFolder . '/480p.m3u8';

    $handle = $mysql->prepare('UPDATE video SET progresstext=? WHERE id=?');
    $handle->bindValue(1, 'Encoding HLS 240p');
    $handle->bindValue(2, $videoId);
    $handle->execute();
    $video = $ffmpeg->open($file);
    $video
        ->filters()
        ->resize(new FFMpeg\Coordinate\Dimension(360, 240))
        ->synchronize();
    $hls240 = new FFMpeg\Format\Video\X264('aac', 'libx264');
    $hls240->on('progress', function ($video, $format, $percentage) use ($mysql, $videoId) {
        print("Transcoding HLS 240p: $percentage %\n");
        $handle = $mysql->prepare('UPDATE video SET progresssub=?, progress=? WHERE id=?');
        $handle->bindValue(1, $percentage);
        $handle->bindValue(2, $percentage / 3);
        $handle->bindValue(3, $videoId);
        $handle->execute();
    });
    $hls240->setKiloBitRate(180);
    $hls240->setAudioKiloBitRate(80);
    $hls240->setAdditionalParameters(['-f', 'hls',
        '-profile:v', 'baseline', '-level', '3.0',
        //'-vf', 'scale=360x240',
        '-start_number', '0', '-hls_time', '10', '-hls_list_size', '0',
        // experimental
        '-strict', '-2']);
    $video->save($hls240, $hls240file);

    $handle = $mysql->prepare('UPDATE video SET progresstext=? WHERE id=?');
    $handle->bindValue(1, 'Encoding HLS 480p');
    $handle->bindValue(2, $videoId);
    $handle->execute();
    $video = $ffmpeg->open($file);
    $video
        ->filters()
        ->resize(new FFMpeg\Coordinate\Dimension(720, 480))
        ->synchronize();
    $hls480 = new FFMpeg\Format\Video\X264('aac', 'libx264');
    $hls480->on('progress', function ($video, $format, $percentage) use ($mysql, $videoId) {
        print("Transcoding HLS 480p: $percentage %\n");
        $handle = $mysql->prepare('UPDATE video SET progresssub=?, progress=? WHERE id=?');
        $handle->bindValue(1, $percentage);
        $handle->bindValue(2, 33 + ($percentage / 3));
        $handle->bindValue(3, $videoId);
        $handle->execute();
    });
    $hls480->setKiloBitRate(500);
    $hls480->setAudioKiloBitRate(80);
    $hls480->setAdditionalParameters(['-f', 'hls',
        '-profile:v', 'baseline', '-level', '3.0',
        '-start_number', '0', '-hls_time', '10', '-hls_list_size', '0',
        // experimental
        '-strict', '-2']);
    $video->save($hls480, $hls480file);

    $handle = $mysql->prepare('UPDATE video SET progresstext=? WHERE id=?');
    $handle->bindValue(1, 'Creating HLS master playlist');
    $handle->bindValue(2, $videoId);
    $handle->execute();
    $masterFile = $targetFolder . '/master.m3u8';
    print("Creating HLS master playlist for $file : $masterFile ...\n");
    $masterContent = <<<EOD
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=580000,RESOLUTION=720x480
480p.m3u8
#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=260000,RESOLUTION=360x240
240p.m3u8
EOD;
    $mf = fopen($masterFile, 'w');
    fwrite($mf, $masterContent);
    fclose($mf);

    $handle = $mysql->prepare('UPDATE video SET progresstext=? WHERE id=?');
    $handle->bindValue(1, 'Extracting audio');
    $handle->bindValue(2, $videoId);
    $handle->execute();
    $mp3File = $targetFolder . '/audio.mp3';
    print("Creating audio only for $file ...\n");
    $video = $ffmpeg->open($file);
    $format = new FFMpeg\Format\Audio\Mp3();
    $format->on('progress', function ($video, $format, $percentage) use ($mysql, $videoId) {
        print("Transcoding MP3: $percentage %\n");
        $handle = $mysql->prepare('UPDATE video SET progresssub=?, progress=? WHERE id=?');
        $handle->bindValue(1, $percentage);
        $handle->bindValue(2, 66 + ($percentage / 3));
        $handle->bindValue(3, $videoId);
        $handle->execute();
    });
    $format->setAudioKiloBitrate(96);
    $video->save($format, $mp3File);
    
    $sourceFile = $targetFolder . '/source.' . $path_parts['extension'];
    print("Moving source file to $sourceFile ...\n");
    rename($file, $sourceFile);

    print("Done processing $processedFolder\n");
    $handle = $mysql->prepare('UPDATE video SET progresstext=?, progresssub=?, progress=? WHERE id=?');
    $handle->bindValue(1, 'Done');
    $handle->bindValue(2, 100);
    $handle->bindValue(3, 100);
    $handle->bindValue(4, $videoId);
    $handle->execute();
}

function mainLoop() {
    // Create a new connection.
    // You'll probably want to replace hostname with localhost in the first parameter.
    // Note how we declare the charset to be utf8mb4.  This alerts the connection that we'll be passing UTF-8 data.  This may not be required depending on your configuration, but it'll save you headaches down the road if you're trying to store Unicode strings in your database.  See "Gotchas".
    // The PDO options we pass do the following:
    // \PDO::ATTR_ERRMODE enables exceptions for errors.  This is optional but can be handy.
    // \PDO::ATTR_PERSISTENT disables persistent connections, which can cause concurrency issues in certain cases.  See "Gotchas".
    $link = new \PDO('mysql:host=localhost;dbname=vcp101;charset=utf8mb4',
                        'root',
                        '',
                        array(
                            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                            \PDO::ATTR_PERSISTENT => false
                        )
                    );
    
    $incomingFolder = 'C:/xampp/htdocs/vcp101-2/incoming';
    $processedFolder = 'C:/xampp/htdocs/vcp101-2/processed';
    while (true) {
        print("Checking $incomingFolder at " . date('c') . " ...\n");

        $files = array_diff(scandir($incomingFolder), array('.', '..'));
        print('Found files: ' . json_encode($files) . "\n");
        foreach ($files as $filename) {
            $file = $incomingFolder .'/'. $filename;
            $path_parts = pathinfo($file);
            if ($path_parts['extension'] == 'mp4') {
                encode($file, $processedFolder, $link);
            } else {
                print("Skipping non-processable file $file\n");
            }
        }

        sleep(5);
    }
}

mainLoop();