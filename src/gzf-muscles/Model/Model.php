<?php
namespace GZFMuscles\Model;

class Model
{
    public function exchangeArray(array $data)
    {
        $attributes = array_keys($this->getArrayCopy());

        foreach($data as $key => $value) {
            if (in_array($key, $attributes)) {
                /**
                 * Documentation example (!empty()) is flawed, since it considers values such as 0, "0" and false
                 * as being not empty, which the negation makes it false. These values may be returned from a data 
                 * source, e.g.: a boolean column in MySQL which is really a tinyint column that returns 0 in case of 
                 * falsehood.
                 *
                 * However, the empty string ("") should be considered null, which the nagation of is_null() fails to 
                 * accomplish.
                 */

                if ($value == "") {
                    $value = null;
                }

                if (!is_null($value)) {
                    $this->$key = $value;
                }
            }
        }
    }

    public function getArrayCopy()
    {
        return get_object_vars($this);
    }
}
