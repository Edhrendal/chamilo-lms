<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CourseBundle;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CourseBundle\Entity\CTool;

/**
 * Class ToolChain
 * @package Chamilo\CourseBundle
 */
class ToolChain
{
    protected $tools;

    /**
     * Construct
     */
    public function __construct()
    {
        $this->tools = array();
    }

    /**
     * @param $tool
     */
    public function addTool($tool)
    {
        $this->tools[] = $tool;
    }

    /**
     * @return array
     */
    public function getTools()
    {
        return $this->tools;
    }

    /**
     * @param Course $course
     * @return Course
     */
    public function addToolsInCourse(Course $course)
    {
        $tools = $this->getTools();
        foreach ($tools as $tool) {
            $toolEntity = new CTool();
            $toolEntity
                ->setCId($course->getId())
                ->setImage($tool->getImage())
                ->setName($tool->getName())
                ->setLink($tool->getLink())
                ->setTarget($tool->getTarget())
                ->setCategory($tool->getCategory());

            $course->addTools($toolEntity);
        }

        return $course;
    }
}
