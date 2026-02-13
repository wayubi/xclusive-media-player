#!/usr/bin/env bash
# refresh_metadata.sh - Force refresh metadata for all files in current directory
# Usage: refresh_metadata

shopt -s nullglob

echo "Scanning current directory for files..."
files=(*)
total=${#files[@]}

if (( total == 0 )); then
  echo "No files found in current directory."
  exit 0
fi

echo "Found $total files. Refreshing metadata..."
echo ""

# Get current directory path
PWD=$(pwd)

# Process each file
for i in "${!files[@]}"; do
  f="${files[$i]}"
  num=$((i + 1))
  
  # Skip directories
  if [[ -d "$f" ]]; then
    echo "[$num/$total] $f - Skipped (directory)"
    continue
  fi
  
  # Determine file type
  ext="${f##*.}"
  ext_lower=$(echo "$ext" | tr '[:upper:]' '[:lower:]')
  
  text_extensions="txt nfo sfv md log json xml csv yaml yml conf cfg ini"
  
  if [[ " $text_extensions " =~ " $ext_lower " ]]; then
    filetype="text"
  else
    filetype="video"
  fi
  
  echo -n "[$num/$total] $f - Refreshing $filetype metadata..."
  
  # Call PHP to refresh metadata
  php -r "
    require_once '/var/www/html/lib/MetadataDatabase.php';
    \$metaDb = new MetadataDatabase();
    \$fsPath = '$PWD/$f';
    
    // Convert to web path
    \$root = realpath('/var/www/html/volumes');
    \$webPath = '/volumes' . str_replace(\$root, '', \$fsPath);
    
    // Delete existing metadata
    \$metaDb->deleteMetadata(\$fsPath);
    
    // Generate new metadata
    if ('$filetype' === 'text') {
      // Text file metadata
      \$output = [
        'file' => basename(\$fsPath),
        'folder' => basename(dirname(\$fsPath)),
        'filesize' => filesize(\$fsPath),
        'text' => ['encoding' => 'utf-8'],
        'optimizationStatus' => ['isOptimized' => true, 'issues' => []]
      ];
      \$metaDb->saveMetadata(\$webPath, \$fsPath, \$output);
    } else {
      // Video file metadata - use ffprobe
      \$cmd = sprintf('ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1', escapeshellarg(\$fsPath));
      \$raw = shell_exec(\$cmd);
      
      if (\$raw && \$meta = json_decode(\$raw, true)) {
        \$video = null;
        \$audio = null;
        
        foreach (\$meta['streams'] ?? [] as \$stream) {
          if (\$stream['codec_type'] === 'video' && !\$video) {
            \$video = \$stream;
          }
          if (\$stream['codec_type'] === 'audio' && !\$audio) {
            \$audio = \$stream;
          }
        }
        
        // Calculate optimization status
        \$isOptimized = true;
        \$issues = [];
        \$ext = strtolower(pathinfo(\$fsPath, PATHINFO_EXTENSION));
        
        \$nonStreamingContainers = ['avi', 'flv', 'wmv', 'mkv', 'mpeg', 'mpg'];
        if (in_array(\$ext, \$nonStreamingContainers)) {
          \$isOptimized = false;
          \$issues[] = 'Non-streaming container: ' . strtoupper(\$ext);
        }
        
        \$nonStreamingCodecs = ['wmv3', 'flv1', 'wmv2', 'mpeg4', 'wmv1', 'mpeg1video'];
        if (\$video && in_array(\$video['codec_name'] ?? '', \$nonStreamingCodecs)) {
          \$isOptimized = false;
          \$issues[] = 'Non-streaming codec: ' . (\$video['codec_name'] ?? 'unknown');
        }
        
        // Check faststart for MP4/MOV/M4V
        if (in_array(\$ext, ['mp4', 'mov', 'm4v'])) {
          \$fh = fopen(\$fsPath, 'rb');
          if (\$fh) {
            \$hasFaststart = null;
            while (!feof(\$fh)) {
              \$header = fread(\$fh, 8);
              if (strlen(\$header) < 8) break;
              list(, \$size) = unpack('N', substr(\$header, 0, 4));
              \$type = substr(\$header, 4, 4);
              if (\$type === 'moov') { \$hasFaststart = true; break; }
              if (\$type === 'mdat') { \$hasFaststart = false; break; }
              if (\$size === 0) break;
              if (\$size === 1) { fseek(\$fh, 8, SEEK_CUR); }
              else { fseek(\$fh, \$size - 8, SEEK_CUR); }
            }
            fclose(\$fh);
            if (\$hasFaststart === false) {
              \$isOptimized = false;
              \$issues[] = 'Faststart not enabled (moov atom not at start)';
            }
          }
        }
        
        // Calculate FPS
        \$fps = null;
        if (\$video && isset(\$video['avg_frame_rate']) && \$video['avg_frame_rate'] !== '0/0') {
          \$fpsParts = explode('/', \$video['avg_frame_rate']);
          if (count(\$fpsParts) === 2 && \$fpsParts[1] != 0) {
            \$fps = (float)\$fpsParts[0] / (float)\$fpsParts[1];
          }
        }
        
        \$output = [
          'file' => basename(\$fsPath),
          'folder' => basename(dirname(\$fsPath)),
          'filesize' => filesize(\$fsPath),
          'duration' => \$meta['format']['duration'] ?? null,
          'bitrate' => isset(\$meta['format']['bit_rate']) ? (int)\$meta['format']['bit_rate'] : null,
          'container' => \$meta['format']['format_name'] ?? null,
          'video' => \$video ? [
            'codec' => \$video['codec_name'] ?? null,
            'width' => \$video['width'] ?? null,
            'height' => \$video['height'] ?? null,
            'fps' => \$fps,
            'pix_fmt' => \$video['pix_fmt'] ?? null,
          ] : null,
          'audio' => \$audio ? [
            'codec' => \$audio['codec_name'] ?? null,
            'channels' => \$audio['channels'] ?? null,
            'sample_rate' => \$audio['sample_rate'] ?? null,
          ] : null,
          'optimizationStatus' => [
            'isOptimized' => \$isOptimized,
            'issues' => \$issues
          ],
        ];
        
        \$metaDb->saveMetadata(\$webPath, \$fsPath, \$output);
      }
    }
  " 2>/dev/null
  
  if [[ $? -eq 0 ]]; then
    echo " Done"
  else
    echo " Failed"
  fi
done

echo ""
echo "Refresh complete. $total files processed."
