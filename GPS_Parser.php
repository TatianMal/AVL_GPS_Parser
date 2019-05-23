<?php

class GPS_Parser
{
    protected $data;
    protected $geo_data_array = [];

    protected $package_offsets = [
        "zeroes" => 4,
        "data_length" => 4,
        "codec_id" => 1,
        "crc" => 4
    ];

    protected $byte_size_num_record = 1;
    protected $byte_size_io_element_count = 1;

    protected $avl_offsets = [
        "timestamp" => 8,
        "priority" => 1,
        "GPS" => 15,
    ];

    protected $gps_offsets = [
        "longitude" => 4,
        "latitude" => 4,
        "altitude" => 2,
        "angle" => 2,
        "satellites" => 1,
        "speed" => 2
    ];

    protected $array_io_bytes = [1, 2, 4, 8];

    function __construct($hex_data)
    {
        $this->data = str_split($hex_data, 2);
    }

    protected function hex_to_dec($hex_data)
    {
        return base_convert($hex_data, 16, 10);
    }

    protected function cut_data($start_offset = 0, $end_offset = 0)
    {
        if(!($start_offset == 0 && $end_offset == 0))
        {
            $length_data_array = count($this->data) - $start_offset - $end_offset;
            $this->data = array_slice($this->data, $start_offset, $length_data_array);
        }
    }

    public function cut_package_data()
    {
        $start_offset = $this->package_offsets["zeroes"] + $this->package_offsets["data_length"]
                        + $this->package_offsets["codec_id"];
        $end_offset = $this->package_offsets["crc"];

        $this->cut_data($start_offset, $end_offset);
    }

    protected function parse_datetime($date_str)
    {
        $converted_data = (float) $this->hex_to_dec($date_str);
        $converted_data = $converted_data / 1000;
        $converted_data = (int) $converted_data;
        $converted_data = date("Y-m-d H:i:s", $converted_data);
        return $converted_data;
    }

    public function parse_records()
    {
        $data_timestamp = array_slice($this->data, 0, $this->avl_offsets["timestamp"]);
        $data_timestamp = $this->parse_datetime(implode($data_timestamp));

        $start_offset = $this->avl_offsets["timestamp"] + $this->avl_offsets["priority"];
        $this->cut_data($start_offset);

        $gps_length = $this->avl_offsets["GPS"];
        $gps_data = array_slice($this->data, 0, $gps_length);
        $gps_converted_array = $this->parse_gps_data($gps_data);
        $gps_converted_array["datetime"] = $data_timestamp;
        array_push($this->geo_data_array, $gps_converted_array);

        $offset_to_io = $gps_length;
        $this->cut_data($offset_to_io);

        $offset_to_next_data = $this->get_io_offset();
        $this->cut_data($offset_to_next_data);
    }

    protected function convert_gps_data($type_data, $data)
    {
        $converted_data = "";

        if($type_data == "longitude" || $type_data == "latitude")
        {
            $bytes_to_check_sign = substr($data, 0, 2);
            $bytes_to_check_sign = base_convert($bytes_to_check_sign, 16, 2);
            $sign_bit = "";
            if(strlen($bytes_to_check_sign) < 8)
                $sign_bit = 0;
            else
            {
                $sign_bit = substr($data, 0, 1);
            }

            $unsign_converted_data = (float) $this->hex_to_dec($data);
            $unsign_converted_data /= 10000000;

            if($sign_bit == "1")
                $converted_data = "-" . (string) $unsign_converted_data;
            else
                $converted_data = (string) $unsign_converted_data;
        }
        else
        {
            $converted_data = (int) $this->hex_to_dec($data);
        }

        return $converted_data;
    }

    public function parse_gps_data($gps_data)
    {
        $gps_array = [];

        foreach($this->gps_offsets as $name_gps => $offset)
        {
            if($name_gps == "angle")
            {
                $gps_data = array_slice($gps_data, $offset);
                continue;
            }

            $data = implode(array_slice($gps_data, 0, $offset));
            $converted_data = $this->convert_gps_data($name_gps, $data);
            $gps_array[$name_gps] = $converted_data;

            $gps_data = array_slice($gps_data, $offset);
        }

        return $gps_array;
    }

    protected function count_length_io_bytes($num_count, $num_bytes)
    {
        return (1 + $num_bytes) * $num_count;
    }

    public function get_io_offset()
    {
        $io_global_offset = 2;
        $this->cut_data($io_global_offset);

        $io_offset = 0;
        foreach($this->array_io_bytes as $num_bytes)
        {
            $count_io_records = $this->data[$io_offset];
            $count_io_records = (int) $this->hex_to_dec($count_io_records);
            $current_io_offset = $this->count_length_io_bytes($count_io_records, $num_bytes);
            $io_offset += $current_io_offset + $this->byte_size_io_element_count;
        }

        return $io_offset;
    }

    public function parse_avl_data()
    {
        $this->cut_package_data();

        $bytes_length = $this->data[0];
        $avl_data_length = $this->hex_to_dec($bytes_length);
        $this->cut_data(1);

        for($i = 0; $i < $avl_data_length; $i++)
            $this->parse_records();

        return $this->geo_data_array;
    }


}