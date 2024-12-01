<?php

class HLSProcessor
{
    public static function createHLSWithAudioTracks($pathFileName, $destinationFile)
    {
        // Detect video resolution and audio tracks
        $resolution = self::getResolution($pathFileName);
        $audioTracks = self::getAudioTracks($pathFileName); // Detect audio tracks
        $encoderConfig = Format::loadEncoderConfiguration();
        $resolutions = $encoderConfig['resolutions'];
        $bandwidth = $encoderConfig['bandwidth'];
        $videoFramerate = $encoderConfig['videoFramerate'];
        $parts = pathinfo($destinationFile);
        $destinationFile = "{$parts["dirname"]}/{$parts["filename"]}/";

        // Log and create output directory
        _error_log("HLSProcessor: createHLSWithAudioTracks($pathFileName, $destinationFile) [resolutions=" . json_encode($resolutions) . "][audioTracks=" . json_encode($audioTracks) . "] [height={$resolution}] [$destinationFile=$destinationFile]");
        mkdir($destinationFile);
        mkdir($destinationFile . 'audio_tracks');

        // Check if the key and keyinfo files already exist
        $keyFileName = null;
        $keyInfoFile = $destinationFile . "keyinfo";

        if (file_exists($keyInfoFile)) {
            // Reuse existing keyinfo and key
            _error_log("HLSProcessor: Reusing existing key and keyinfo");
            $keyFileName = basename(file($keyInfoFile)[0]); // Extract key filename from keyinfo
        } else {
            // Create encryption key
            _error_log("HLSProcessor: Creating new encryption key and keyinfo");
            $key = openssl_random_pseudo_bytes(16);
            $keyFileName = "enc_" . uniqid() . ".key";
            file_put_contents($destinationFile . $keyFileName, $key);

            // Create keyinfo file for HLS encryption
            $str = "../{$keyFileName}".PHP_EOL;
            $str .= "{$destinationFile}{$keyFileName}";
            file_put_contents($keyInfoFile, $str);
        }

        // Initialize the master playlist content
        $masterPlaylist = "#EXTM3U".PHP_EOL;
        $masterPlaylist .= "#EXT-X-VERSION:3".PHP_EOL;

        // Generate separate audio-only HLS streams for each audio track
        foreach ($audioTracks as $key => $track) {
            $language = isset($track->language) ? $track->language : "lang" . ($track->index + 1); // Assign language name, customize as needed

            $langDir = preg_replace('/[^a-z0-9_-]/i', '', $language);

            mkdir("{$destinationFile}audio_tracks/{$langDir}");

            $audioFile = "{$destinationFile}audio_tracks/{$langDir}/audio.m3u8";
            $audioTsPattern = "{$destinationFile}audio_tracks/{$langDir}/audio_%03d.ts"; // Pattern for audio .ts segments

            // Correctly map the audio track and add VOD parameters
            $audioCommand = get_ffmpeg() . " -i {$pathFileName} -map 0:a:{$track->index} -c:a aac -b:a 128k " .
                "-movflags +faststart -f hls -hls_time 6 -hls_playlist_type vod " .
                "-hls_segment_filename \"{$audioTsPattern}\" {$audioFile}";

            $audioCommand = removeUserAgentIfNotURL($audioCommand);
            _error_log("Executing audio FFmpeg command: {$audioCommand}");
            exec($audioCommand, $output, $result_code); // Execute FFmpeg command

            if (!file_exists($audioFile)) {
                _error_log("audioFile error: {$audioCommand} " . json_encode(array($output)));
                rmdir("{$destinationFile}audio_tracks/{$langDir}");
                unset($audioTracks[$key]);
            } else {
                // Add audio track entry to the master playlist
                $default = ($track->index == 0) ? "YES" : "NO"; // Set first audio track as default
                $masterPlaylist .= "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio_group\",NAME=\"{$track->title}\",LANGUAGE=\"{$language}\",DEFAULT={$default},AUTOSELECT=YES,URI=\"audio_tracks/{$langDir}/audio.m3u8\"".PHP_EOL;
            }
        }

        $ffmpegCommand = '';
        $resolutionsFound = 0;
        // Generate HLS files for each resolution
        foreach ($resolutions as $key => $value) {
            if ($resolution >= $value) {
                $encodingSettings = Format::ENCODING_SETTINGS[$value];
                $rate = $encodingSettings['maxrate']; // Use the maxrate from ENCODING_SETTINGS
                $framerate = isset($videoFramerate[$key]) && $videoFramerate[$key] > 0 ? $videoFramerate[$key] : 30;
                $dir = $destinationFile . "res{$value}/";
                mkdir($dir);
                $outputFile = "{$dir}index.m3u8";

                // Add resolution playlist entry to the master playlist
                $masterPlaylist .= "#EXT-X-STREAM-INF:BANDWIDTH=" . ($rate * 1000) . ",RESOLUTION=-2x{$value},AUDIO=\"audio_group\"".PHP_EOL;
                $masterPlaylist .= "res{$value}/index.m3u8".PHP_EOL;

                // Append FFmpeg command for this resolution
                $ffmpegCommand .= self::getFFmpegCommandForResolution($pathFileName, $value, $rate, $framerate, $audioTracks, $keyInfoFile, $outputFile);

                $resolutionsFound++;
            } else {
                _error_log("HLSProcessor: Skipped resolution {$value} for {$pathFileName} (video height is {$resolution})");
            }
        }

        if (empty($resolutionsFound)) {
            // did not find any resolution, process the default one
            $encodingSettings = Format::ENCODING_SETTINGS[480];
            $rate = $encodingSettings['maxrate']; // Use the maxrate from ENCODING_SETTINGS
            $framerate = isset($videoFramerate[$key]) && $videoFramerate[$key] > 0 ? $videoFramerate[$key] : 30;
            $dir = $destinationFile . "res{$resolution}/";
            mkdir($dir);
            $outputFile = "{$dir}index.m3u8";

            // Add resolution playlist entry to the master playlist
            $masterPlaylist .= "#EXT-X-STREAM-INF:BANDWIDTH=" . ($rate * 1000) . ",RESOLUTION=-2x{$resolution},AUDIO=\"audio_group\"".PHP_EOL;
            $masterPlaylist .= "res{$resolution}/index.m3u8".PHP_EOL;

            // Append FFmpeg command for this resolution
            $ffmpegCommand .= self::getFFmpegCommandForResolution($pathFileName, $resolution, $rate, $framerate, $audioTracks, $keyInfoFile, $outputFile);

            $resolutionsFound++;
        }

        $ffmpegCommand = get_ffmpeg() . " -i {$pathFileName} " . $ffmpegCommand;
        $ffmpegCommand = removeUserAgentIfNotURL($ffmpegCommand);

        // Write the master playlist to the destination file
        file_put_contents($destinationFile . "index.m3u8", $masterPlaylist);
        _error_log("Master playlist written to: {$destinationFile}index.m3u8");

        return array($destinationFile, $ffmpegCommand);
    }

    // FFmpeg Command Generation for HLS with Audio Tracks for a Specific Resolution
    private static function getFFmpegCommandForResolution($inputFile, $resolution, $bitrate, $framerate, $audioTracks, $keyInfoFile, $outputFile)
    {
        $command = " -vf scale=-2:{$resolution} -b:v {$bitrate}k -r {$framerate} " .
            "-movflags +faststart -hls_time 6 -hls_key_info_file {$keyInfoFile} -hls_playlist_type vod " .
            "-map 0:v -c:v h264 -profile:v main -pix_fmt yuv420p -f hls {$outputFile}";

        return $command;
    }

    // Function to get video resolution
    private static function getResolution($pathFileName)
    {
        $command = get_ffprobe() . " -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 {$pathFileName}";
        return (int) shell_exec($command);
    }

    // Function to detect audio tracks and their metadata
    private static function getAudioTracks($pathFileName)
    {
        $command = get_ffprobe() . " -v error -select_streams a -show_entries stream=index:stream_tags=language,title -of json {$pathFileName}";
        $output = shell_exec($command);
        $audioInfo = json_decode($output, true);

        $tracks = [];
        foreach ($audioInfo['streams'] as $index => $stream) {
            $track = new stdClass();
            $track->index = $index;
            $track->language = isset($stream['tags']['language']) ? $stream['tags']['language'] : 'Default';
            $track->title = isset($stream['tags']['title']) ? $stream['tags']['title'] : $track->language;

            if ($track->language == 'und') {
                $track->language = 'Default';
            }
            if ($track->title == 'und') {
                $track->title = 'Default';
            }
            $tracks[] = $track;
        }

        return $tracks;
    }
}